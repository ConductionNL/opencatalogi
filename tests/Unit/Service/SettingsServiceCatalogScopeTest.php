<?php

/**
 * Tests for the catalogue scope backfill after a settings import.
 *
 * A seeded catalogue names its register and schema by slug, because the ids only
 * exist after the import. `PublicationService::isObjectInCatalogScope()` intvals
 * every scope entry, so a slug left in place reads as 0 and the catalogue serves
 * nothing. These tests drive the real `backfillCatalogScopes()` over OpenRegister
 * doubles and read what it saves.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 */
class SettingsServiceCatalogScopeTest extends TestCase {

	/**
	 * Every saveObject call, as [object, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * Skip where OpenRegister's real classes are not next to the app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach ([ObjectService::class, RegisterMapper::class, SchemaMapper::class, Register::class, Schema::class] as $class) {
			if (class_exists($class) === false) {
				$this->markTestSkipped('OpenRegister source is not next to this app: ' . $class . ' is missing.');
			}
		}
	}//end setUp()

	/**
	 * Run the backfill over the given catalogues.
	 *
	 * @param array<int, array<string, mixed>> $catalogues The catalogues searchObjects returns.
	 * @param array<string, string> $config App config values.
	 *
	 * @return void
	 */
	private function backfill(array $catalogues, array $config = []): void {
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
				$this->saved[] = [$object, $uuid];
				return new ObjectEntity();
			}
		);

		$registers = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ($id !== 'publication') {
					throw new RuntimeException('no register ' . $id);
				}

				$register = new Register();
				$register->setId(23);
				return $register;
			}
		);

		$schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ($id !== 'publiccode') {
					throw new RuntimeException('no schema ' . $id);
				}

				$schema = new Schema();
				$schema->setId(143);
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

		$method = new ReflectionMethod(SettingsService::class, 'backfillCatalogScopes');
		$method->setAccessible(true);
		$method->invoke($service);
	}//end backfill()

	/**
	 * The seeded Componenten catalogue ends up with ids, and keeps its other fields.
	 *
	 * @return void
	 */
	public function testASeededCatalogueGetsItsSlugsReplacedByIds(): void {
		$this->backfill(
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

		$this->assertCount(1, $this->saved);
		[$object, $uuid] = $this->saved[0];
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
		$this->backfill(
			catalogues: [
				['@self' => ['id' => 'cat-woo'], 'title' => 'Woo', 'registers' => ['23'], 'schemas' => [7, '9']],
			]
		);

		$this->assertSame([], $this->saved);
	}//end testACatalogueWithIdsIsNotSaved()

	/**
	 * A slug that resolves to nothing stays, and is not saved as if resolved.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugIsLeftForTheNextImport(): void {
		$this->backfill(
			catalogues: [
				['@self' => ['id' => 'cat-later'], 'registers' => ['23'], 'schemas' => ['not-imported-yet']],
			]
		);

		$this->assertSame([], $this->saved);
	}//end testAnUnknownSlugIsLeftForTheNextImport()

	/**
	 * A catalogue without a scope still gets the publication register and schema.
	 *
	 * @return void
	 */
	public function testACatalogueWithoutAScopeGetsThePublicationScope(): void {
		$this->backfill(
			catalogues: [
				['@self' => ['id' => 'cat-publications'], 'title' => 'Publications'],
				['@self' => ['id' => 'cat-half'], 'registers' => ['publication'], 'schemas' => []],
			]
		);

		$this->assertCount(2, $this->saved);
		$this->assertSame(['23'], $this->saved[0][0]['registers']);
		$this->assertSame(['7'], $this->saved[0][0]['schemas']);
		$this->assertSame(['23'], $this->saved[1][0]['registers'], 'The slug is resolved.');
		$this->assertSame(['7'], $this->saved[1][0]['schemas'], 'The empty schema list is backfilled.');
	}//end testACatalogueWithoutAScopeGetsThePublicationScope()

	/**
	 * Without the publication configuration nothing is read or saved.
	 *
	 * @return void
	 */
	public function testNothingHappensBeforeThePublicationTypeIsConfigured(): void {
		$this->backfill(
			catalogues: [['@self' => ['id' => 'cat-x'], 'registers' => ['publication'], 'schemas' => ['publiccode']]],
			config: ['publication_schema' => '']
		);

		$this->assertSame([], $this->saved);
	}//end testNothingHappensBeforeThePublicationTypeIsConfigured()
}//end class
