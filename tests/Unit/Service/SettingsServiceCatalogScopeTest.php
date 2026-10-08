<?php

/**
 * Tests for the catalogue scope backfill (publish-from-stackiq): a seeded
 * catalogue that names stackiq by slug resolves inside stackiq's register,
 * records what is still pending, and is published exactly once.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 */
class SettingsServiceCatalogScopeTest extends TestCase {

	/**
	 * App config values, as the service reads and writes them.
	 *
	 * @var array<string, string>
	 */
	private array $appConfig = [];

	/**
	 * Every catalogue the ObjectService double saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Every saveObject call of the publication harness, as [object, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $savedWithUuid = [];

	/**
	 * The stored catalogues the ObjectService double returns.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $catalogs = [];

	/**
	 * The seeded catalogue as OpenRegister stores it before resolution.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(array $override = []): array {
		return array_merge(
			[
				'@self' => ['id' => 'cat-uuid-1'],
				'title' => 'Applicatielandschap',
				'slug' => 'applicatielandschap',
				'listed' => true,
				'status' => 'stable',
				'registers' => ['stackiq'],
				'schemas' => ['module', 'suite', 'catalogService', 'connection', 'usage'],
			],
			$override
		);
	}//end seed()

	/**
	 * Build the service over an instance where stackiq is (or is not) installed.
	 *
	 * Register `stackiq` is 21 and lists the schemas in $stackiqSchemas.
	 * Schema 9 `module` lives in another register and must never be taken.
	 *
	 * @param bool $stackiq Whether register `stackiq` exists.
	 * @param array<int, int> $stackiqSchemas The schema ids register 21 lists.
	 *
	 * @return SettingsService
	 */
	private function service(bool $stackiq, array $stackiqSchemas = [119, 105, 106, 112, 110]): SettingsService {
		$this->appConfig = [
			'publication_register' => '23',
			'publication_schema' => '126',
			'catalog_register' => '23',
			'catalog_schema' => '127',
		] + $this->appConfig;

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appConfig[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfig[$key] = $value;
				return true;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$register = new Register();
		$register->setId(21);
		$register->setSlug('stackiq');
		$register->setSchemas($stackiqSchemas);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id) use ($stackiq, $register): Register {
				if ($stackiq === true && ((string)$id === 'stackiq' || (string)$id === '21')) {
					return $register;
				}

				throw new DoesNotExistException('no register ' . $id);
			}
		);

		$slugs = [119 => 'module', 105 => 'suite', 106 => 'catalogService', 112 => 'connection', 110 => 'usage', 9 => 'module'];
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

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(fn (): array => $this->catalogs);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Db\RegisterMapper' => $registers,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
			}
		);

		return new SettingsService(
			$config,
			$container,
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);
	}//end service()

	/**
	 * With stackiq installed the seed resolves inside stackiq's register and is published once.
	 *
	 * @return void
	 */
	public function testTheSeedResolvesInsideStackiqAndIsPublishedOnce(): void {
		$this->catalogs = [$this->seed()];
		$this->service(stackiq: true)->backfillCatalogScopes();

		$this->assertCount(1, $this->saved);
		$this->assertSame(['21'], $this->saved[0]['registers']);
		// 119, not 9: schema 9 is also `module` but lives in another register.
		$this->assertSame(['119', '105', '106', '112', '110'], $this->saved[0]['schemas']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->saved[0]['published']);
		$this->assertSame('[]', $this->appConfig[SettingsService::CATALOG_SCOPE_PENDING_KEY]);
		$this->assertSame('["applicatielandschap"]', $this->appConfig['catalog_seeds_published']);
	}//end testTheSeedResolvesInsideStackiqAndIsPublishedOnce()

	/**
	 * Without stackiq nothing is saved, the catalogue stays unpublished, and `stackiq` is pending.
	 *
	 * @return void
	 */
	public function testWithoutStackiqTheSeedStaysOnSlugsAndPending(): void {
		$this->catalogs = [$this->seed()];
		$this->service(stackiq: false)->backfillCatalogScopes();

		$this->assertSame([], $this->saved);
		$this->assertSame('["stackiq"]', $this->appConfig[SettingsService::CATALOG_SCOPE_PENDING_KEY]);
		$this->assertArrayNotHasKey('catalog_seeds_published', $this->appConfig);
	}//end testWithoutStackiqTheSeedStaysOnSlugsAndPending()

	/**
	 * A register that does not list every schema yet: partial ids, the register id pending, not published.
	 *
	 * @return void
	 */
	public function testAPartialScopeIsSavedPendingAndUnpublished(): void {
		$this->catalogs = [$this->seed()];
		$this->service(stackiq: true, stackiqSchemas: [119, 105])->backfillCatalogScopes();

		$this->assertCount(1, $this->saved);
		$this->assertSame(['119', '105', 'catalogService', 'connection', 'usage'], $this->saved[0]['schemas']);
		$this->assertArrayNotHasKey('published', $this->saved[0]);
		$this->assertSame('["21"]', $this->appConfig[SettingsService::CATALOG_SCOPE_PENDING_KEY]);
	}//end testAPartialScopeIsSavedPendingAndUnpublished()

	/**
	 * A seed the pre-save listener already resolved is still published once, and an unpublish afterwards sticks.
	 *
	 * @return void
	 */
	public function testAnAlreadyResolvedSeedIsPublishedOnceAndAnUnpublishSticks(): void {
		$this->catalogs = [$this->seed(['registers' => [21], 'schemas' => [119, 105, 106, 112, 110]])];
		$service = $this->service(stackiq: true);
		$service->backfillCatalogScopes();

		$this->assertCount(1, $this->saved);
		$this->assertArrayHasKey('published', $this->saved[0]);

		// The administrator removes the publication date; the next import leaves it alone.
		$this->saved = [];
		$service->backfillCatalogScopes();
		$this->assertSame([], $this->saved);
	}//end testAnAlreadyResolvedSeedIsPublishedOnceAndAnUnpublishSticks()

	/**
	 * A catalogue made in the form (ids, not the seed's slug) is never published by the backfill.
	 *
	 * @return void
	 */
	public function testAHandMadeCatalogueIsLeftAlone(): void {
		$this->catalogs = [$this->seed(['slug' => 'mijn-catalogus', 'registers' => [21], 'schemas' => [119]])];
		$this->service(stackiq: true)->backfillCatalogScopes();

		$this->assertSame([], $this->saved);
	}//end testAHandMadeCatalogueIsLeftAlone()

	/**
	 * Run the backfill over the given catalogues.
	 *
	 * @param array<int, array<string, mixed>> $catalogues The catalogues searchObjects returns.
	 * @param array<string, string> $config App config values.
	 *
	 * @return void
	 */
	private function backfillPublication(array $catalogues, array $config = []): void {
		$config = array_merge(
			[
				'publication_register' => '23',
				'publication_schema' => '7',
				'catalog_register' => '23',
				'catalog_schema' => '8',
			],
			$config
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);

		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjects', 'saveObject'])
			->getMock();
		$objects->method('searchObjects')->willReturn($catalogues);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->assertSame('23', $register);
				$this->assertSame('8', $schema);
				$this->savedWithUuid[] = [$object, $uuid];
				return new ObjectEntity();
			}
		);

		$registers = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ($id !== 'publication' && (string)$id !== '23') {
					throw new RuntimeException('no register ' . $id);
				}

				$register = new Register();
				$register->setId(23);
				$register->setSchemas([7, 143]);
				return $register;
			}
		);

		$schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$slugs = [7 => 'publication', 143 => 'publiccode'];
				if (isset($slugs[(int)$id]) === false) {
					throw new RuntimeException('no schema ' . $id);
				}

				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug($slugs[(int)$id]);
				return $schema;
			}
		);

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objects,
			'OCA\OpenRegister\Db\RegisterMapper' => $registers,
			'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		$service = new SettingsService(
			$appConfig,
			$container,
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);

		$service->backfillCatalogScopes();
	}//end backfill()

	/**
	 * The seeded Componenten catalogue ends up with ids, and keeps its other fields.
	 *
	 * @return void
	 */
	public function testASeededCatalogueGetsItsSlugsReplacedByIds(): void {
		$this->backfillPublication(
			catalogues: [
				[
					'@self' => ['id' => 'cat-componenten'],
					'title' => 'Componenten',
					'registers' => ['publication'],
					'schemas' => ['publiccode'],
					'published' => '2026-10-01 00:00:00',
				],
			]
		);

		$this->assertCount(1, $this->savedWithUuid);
		[$object, $uuid] = $this->savedWithUuid[0];
		$this->assertSame('cat-componenten', $uuid);
		$this->assertSame(['23'], $object['registers']);
		$this->assertSame(['143'], $object['schemas']);
		$this->assertSame('Componenten', $object['title']);
		$this->assertArrayNotHasKey('@self', $object);
		$this->assertSame('2026-10-01T00:00:00+00:00', $object['published'], 'The SQL-style date is saved back as ISO 8601.');
	}//end testASeededCatalogueGetsItsSlugsReplacedByIds()

	/**
	 * A catalogue whose scope already holds ids is left alone.
	 *
	 * @return void
	 */
	public function testACatalogueWithIdsIsNotSaved(): void {
		$this->backfillPublication(
			catalogues: [
				['@self' => ['id' => 'cat-woo'], 'title' => 'Woo', 'registers' => ['23'], 'schemas' => [7, '9']],
			]
		);

		$this->assertSame([], $this->savedWithUuid);
	}//end testACatalogueWithIdsIsNotSaved()

	/**
	 * A slug that resolves to nothing stays, and is not saved as if resolved.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugIsLeftForTheNextImport(): void {
		$this->backfillPublication(
			catalogues: [
				['@self' => ['id' => 'cat-later'], 'registers' => ['23'], 'schemas' => ['not-imported-yet']],
			]
		);

		$this->assertSame([], $this->savedWithUuid);
	}//end testAnUnknownSlugIsLeftForTheNextImport()

	/**
	 * A catalogue without a scope still gets the publication register and schema.
	 *
	 * @return void
	 */
	public function testACatalogueWithoutAScopeGetsThePublicationScope(): void {
		$this->backfillPublication(
			catalogues: [
				['@self' => ['id' => 'cat-publications'], 'title' => 'Publications'],
				['@self' => ['id' => 'cat-half'], 'registers' => ['publication'], 'schemas' => []],
			]
		);

		$this->assertCount(2, $this->savedWithUuid);
		$this->assertSame(['23'], $this->savedWithUuid[0][0]['registers']);
		$this->assertSame(['7'], $this->savedWithUuid[0][0]['schemas']);
		$this->assertSame(['23'], $this->savedWithUuid[1][0]['registers'], 'The slug is resolved.');
		$this->assertSame(['7'], $this->savedWithUuid[1][0]['schemas'], 'The empty schema list is backfilled.');
	}//end testACatalogueWithoutAScopeGetsThePublicationScope()

	/**
	 * Without the publication configuration nothing is read or saved.
	 *
	 * @return void
	 */
	public function testNothingHappensBeforeThePublicationTypeIsConfigured(): void {
		$this->backfillPublication(
			catalogues: [['@self' => ['id' => 'cat-x'], 'registers' => ['publication'], 'schemas' => ['publiccode']]],
			config: ['publication_schema' => '']
		);

		$this->assertSame([], $this->savedWithUuid);
	}//end testNothingHappensBeforeThePublicationTypeIsConfigured()
}//end class
