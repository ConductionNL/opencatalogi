<?php

/**
 * Golden test for `SettingsService::backfillCatalogScopes()`.
 *
 * Runs the backfill over a scripted OpenRegister and compares every search and
 * save it made, and the app config it left, with what was recorded: the config
 * gap and missing-OpenRegister exits, catalogues as entities and as arrays, the
 * slug resolution and what stays pending, the empty-scope patch, the one-time
 * seed publication, the SQL-style date repair, a failing save and a failing
 * container.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/CatalogScopeBackfillGoldenTest.php`.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for SettingsService::backfillCatalogScopes().
 */
class CatalogScopeBackfillGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/catalog-scope-backfill-golden.json';

	/**
	 * The seeded catalogue that names stackiq by slug.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function seed(array $override = []): array {
		return array_merge(
			[
				'@self' => ['id' => 'cat-seed'],
				'title' => 'Applicatielandschap',
				'slug' => 'applicatielandschap',
				'registers' => ['stackiq'],
				'schemas' => ['module', 'suite', 'catalogService', 'connection', 'usage'],
			],
			$override
		);
	}//end seed()

	/**
	 * The config, installed apps, OpenRegister content and catalogues of each case.
	 *
	 * Catalogue entries with `entity` are served as an ObjectEntity whose
	 * jsonSerialize() returns that array.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'publication register missing' => [[
				'config' => ['publication_register' => ''],
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'publication schema missing' => [[
				'config' => ['publication_schema' => ''],
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'catalog register missing' => [[
				'config' => ['catalog_register' => ''],
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'catalog schema missing' => [[
				'config' => ['catalog_schema' => ''],
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'openregister not installed' => [[
				'apps' => ['opencatalogi'],
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'search answers a count' => [[
				'search' => 7,
			]],
			'no catalogues' => [[
				'catalogs' => [],
			]],
			'entity and scalar catalogues' => [[
				'catalogs' => [
					['entity' => ['@self' => ['id' => 'c-entity'], 'title' => 'Entity']],
					'not a catalogue',
					42,
					['@self' => ['id' => 'c-array'], 'registers' => [], 'schemas' => [7]],
				],
			]],
			'missing and empty scopes' => [[
				'catalogs' => [
					['@self' => ['id' => 'c-none'], 'title' => 'None'],
					['@self' => ['id' => 'c-regs'], 'registers' => [], 'schemas' => ['7']],
					['@self' => ['id' => 'c-schemas'], 'registers' => ['23'], 'schemas' => []],
					['@self' => ['id' => 'c-null'], 'registers' => null, 'schemas' => null],
				],
			]],
			'string scope is left alone' => [[
				'catalogs' => [['@self' => ['id' => 'c-str'], 'registers' => 'publication', 'schemas' => 'publiccode']],
			]],
			'one side a string' => [[
				'catalogs' => [['@self' => ['id' => 'c-half'], 'registers' => 'publication', 'schemas' => []]],
			]],
			'ids are left alone' => [[
				'catalogs' => [['@self' => ['id' => 'c-ids'], 'registers' => ['23'], 'schemas' => [7, '143']]],
			]],
			'slugs resolve to ids' => [[
				'catalogs' => [
					[
						'@self' => ['id' => 'c-slugs', 'owner' => 'admin'],
						'title' => 'Componenten',
						'registers' => ['publication'],
						'schemas' => ['publiccode'],
						'published' => '2026-10-01 00:00:00',
						'updated' => '2026-10-02T10:00:00+00:00',
						'count' => 3,
						'note' => '2026-10-01 00:00',
					],
				],
			]],
			'unknown slug stays pending' => [[
				'catalogs' => [
					['@self' => ['id' => 'c-later'], 'registers' => ['23'], 'schemas' => ['not-imported-yet']],
					['@self' => ['id' => 'c-later2'], 'registers' => ['ghost', '23'], 'schemas' => ['not-imported-yet']],
				],
			]],
			'id from the top level' => [[
				'catalogs' => [
					['id' => 'top-level-id', 'title' => 'Top'],
					['title' => 'No id at all'],
					['@self' => [], 'id' => 99],
				],
			]],
			'seed with stackiq is published once' => [[
				'stackiq' => true,
				'catalogs' => [self::seed()],
			]],
			'seed without stackiq stays pending' => [[
				'catalogs' => [self::seed()],
			]],
			'seed with a partial register' => [[
				'stackiq' => true,
				'stackiqSchemas' => [119, 105],
				'catalogs' => [self::seed()],
			]],
			'resolved seed published once' => [[
				'stackiq' => true,
				'catalogs' => [self::seed(['registers' => [21], 'schemas' => [119, 105, 106, 112, 110]])],
			]],
			'resolved seed already published once before' => [[
				'stackiq' => true,
				'config' => ['catalog_seeds_published' => '["other","applicatielandschap"]'],
				'catalogs' => [self::seed(['registers' => [21], 'schemas' => [119]])],
			]],
			'resolved seed with garbage publish memory' => [[
				'stackiq' => true,
				'config' => ['catalog_seeds_published' => '{"broken'],
				'catalogs' => [self::seed(['registers' => [21], 'schemas' => [119]])],
			]],
			'resolved seed with a published date' => [[
				'stackiq' => true,
				'catalogs' => [self::seed(['registers' => [21], 'schemas' => [119], 'published' => '2026-01-01T00:00:00+00:00'])],
			]],
			'hand-made catalogue with ids' => [[
				'stackiq' => true,
				'catalogs' => [self::seed(['slug' => 'mijn-catalogus', 'registers' => [21], 'schemas' => [119]])],
			]],
			'failing save skips to the next' => [[
				'stackiq' => true,
				'failSave' => ['cat-seed', 'c-fail'],
				'catalogs' => [
					self::seed(),
					['@self' => ['id' => 'c-fail']],
					['@self' => ['id' => 'c-ok'], 'registers' => ['ghost'], 'schemas' => []],
				],
			]],
			'container fails' => [[
				'containerFails' => true,
				'catalogs' => [['@self' => ['id' => 'c1']]],
			]],
			'pending is deduplicated' => [[
				'catalogs' => [
					self::seed(['@self' => ['id' => 'a']]),
					self::seed(['@self' => ['id' => 'b'], 'slug' => 'other']),
				],
			]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testBackfillMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->backfill(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testBackfillMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and run the backfill.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The OpenRegister calls and the app config afterwards.
	 */
	private function backfill(array $case): array {
		$calls = [];
		$appConfig = array_merge(
			[
				'publication_register' => '23',
				'publication_schema' => '7',
				'catalog_register' => '23',
				'catalog_schema' => '8',
			],
			($case['config'] ?? [])
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$appConfig): string {
				return ($appConfig[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$appConfig, &$calls): bool {
				$calls[] = ['setValueString', $app, $key, $value];
				$appConfig[$key] = $value;
				return true;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister', 'opencatalogi']));

		$catalogs = [];
		foreach (($case['catalogs'] ?? []) as $catalog) {
			if (is_array($catalog) === true && isset($catalog['entity']) === true) {
				$entity = $this->getMockBuilder(ObjectEntity::class)
					->disableOriginalConstructor()
					->onlyMethods(['jsonSerialize'])
					->getMock();
				$entity->method('jsonSerialize')->willReturn($catalog['entity']);
				$catalog = $entity;
			}

			$catalogs[] = $catalog;
		}

		$search = (array_key_exists('search', $case) === true ? $case['search'] : $catalogs);
		$failSave = ($case['failSave'] ?? []);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use (&$calls, $search) {
				$calls[] = ['searchObjects', $query, $_rbac, $_multitenancy];
				return $search;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use (&$calls, $failSave): ObjectEntity {
				if (is_array($object) === true && isset($object['published']) === true
					&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string)$object['published']) === 1
					&& str_starts_with((string)$object['published'], '2026-10-01') === false
					&& str_starts_with((string)$object['published'], '2026-01-01') === false
				) {
					$object['published'] = '<now, ISO 8601 UTC>';
				}

				$calls[] = ['saveObject', $object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy];
				if (in_array($uuid, $failSave, true) === true) {
					throw new RuntimeException('save failed');
				}

				return new ObjectEntity();
			}
		);

		$stackiq = ($case['stackiq'] ?? false);
		$stackiqSchemas = ($case['stackiqSchemas'] ?? [119, 105, 106, 112, 110]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id) use ($stackiq, $stackiqSchemas): Register {
				$register = new Register();
				if ($stackiq === true && ((string)$id === 'stackiq' || (string)$id === '21')) {
					$register->setId(21);
					$register->setSchemas($stackiqSchemas);
					return $register;
				}

				if ((string)$id === 'publication' || (string)$id === '23') {
					$register->setId(23);
					$register->setSchemas([7, 143]);
					return $register;
				}

				throw new DoesNotExistException('no register ' . $id);
			}
		);

		$slugs = [
			119 => 'module',
			105 => 'suite',
			106 => 'catalogService',
			112 => 'connection',
			110 => 'usage',
			9 => 'module',
			7 => 'publication',
			143 => 'publiccode',
		];
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($slugs): Schema {
				if (isset($slugs[(int)$id]) === false) {
					throw new DoesNotExistException('no schema ' . $id);
				}

				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug($slugs[(int)$id]);
				return $schema;
			}
		);

		$containerFails = ($case['containerFails'] ?? false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $registers, $schemas, $containerFails): object {
				if ($containerFails === true) {
					throw new RuntimeException('container down');
				}

				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objects,
					'OCA\OpenRegister\Db\RegisterMapper' => $registers,
					'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				};
			}
		);

		$service = new SettingsService(
			$config,
			$container,
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);
		$service->backfillCatalogScopes();

		ksort($appConfig);
		return ['calls' => $calls, 'config' => $appConfig];

	}//end backfill()
}//end class
