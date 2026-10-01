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

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
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
}//end class
