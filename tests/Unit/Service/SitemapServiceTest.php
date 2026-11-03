<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapRequest;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for SitemapService.
 */
class SitemapServiceTest extends TestCase {

	/**
	 * Double a class whose method set differs between this tree and CI.
	 *
	 * `Schema::getId()` is a magic `Entity` accessor in the real OpenRegister, so
	 * `createMock()` cannot configure it and PHPUnit raises
	 * MethodCannotBeConfiguredException. The local stub under tests/Stubs declares
	 * it as a real method, so the same call succeeds here and fails in CI. Splitting
	 * the list by `method_exists` at run time satisfies both: `onlyMethods` for what
	 * the class really declares, `addMethods` for what it resolves magically.
	 *
	 * Same helper as RegisterSchemaLinkServiceTest::environmentAwareDouble().
	 *
	 * @param class-string       $class   The class to double.
	 * @param array<int, string> $methods The methods to configure.
	 *
	 * @return MockObject The double.
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = [];
		$absent   = [];
		foreach ($methods as $method) {
			if (method_exists($class, $method) === true) {
				$existing[] = $method;
			} else {
				$absent[] = $method;
			}
		}

		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if ($existing !== []) {
			$builder->onlyMethods($existing);
		}

		if ($absent !== []) {
			$builder->addMethods($absent);
		}

		return $builder->getMock();
	}//end environmentAwareDouble()

	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private SettingsService|MockObject $settingsService;
	private IURLGenerator|MockObject $urlGenerator;
	private IAppConfig|MockObject $config;
	private PublicationQueryService|MockObject $queryService;
	private \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry $wooCategories;

	/** @var \Closure(array): array What the read-rule guard returns; pass-through by default. */
	private \Closure $guard;
	private SitemapService $service;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(IAppConfig::class);

		// The page-size limit is now operator-tunable via the `sitemap_max_per_page`
		// app-config key; honour the default the production code passes.
		$this->config->method('getValueInt')
			->willReturnCallback(
				static fn (string $app, string $key, int $default = 0): int => $default
			);

		// Object visibility is enforced by OpenRegister RBAC inside the _rbac: true
		// searches. The query service is back as a dep for ONE thing: the
		// SCH-PFTS-CAT-002 read-rule guard (WOO-581 review). Pass-through here; the
		// WOO-581 tests below swap $this->guard.
		$this->guard = static fn (array $schemaIds): array => $schemaIds;
		$this->queryService = $this->createMock(PublicationQueryService::class);
		$this->queryService->method('applySchemaScopeReadRuleGuard')
			->willReturnCallback(fn (array $schemaIds): array => ($this->guard)($schemaIds));

		// A real registry over a mocked SettingsService: getObjectService() returns
		// null on the mock, so no local category is read and the registry serves the
		// 18 waardelijst members, which is what the two constants used to hold.
		$this->wooCategories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->settingsService,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new \OCA\OpenCatalogi\Service\TooiVocabularyService()),
		);

		$this->service = new SitemapService(
			new OpenRegisterLookup($this->container, $this->appManager),
			$this->settingsService,
			new InstanceSettings($this->urlGenerator, $this->config),
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->queryService,
			$this->wooCategories
		);
	}

	// ──────────────────────────────────────────────────────────
	// The sitemap files the category registry serves
	// ──────────────────────────────────────────────────────────

	public function testTheRegistryServesOneSitemapPerWaardelijstMember(): void {
		$this->assertCount(18, $this->wooCategories->sitemapFiles());
	}

	public function testEveryWaardelijstMemberHasItsSitemapFile(): void {
		$files = $this->wooCategories->sitemapFiles();
		for ($i = 1; $i <= 18; $i++) {
			$key = sprintf('sitemapindex-diwoo-infocat%03d.xml', $i);
			$this->assertArrayHasKey($key, $files, "Missing key: $key");
		}
	}

	public function testTheFirstSitemapFileCarriesTheFirstCategoryTitle(): void {
		$this->assertEquals(
			'Wetten en algemeen verbindende voorschriften',
			$this->wooCategories->sitemapFiles()['sitemapindex-diwoo-infocat001.xml']
		);
	}

	public function testTheArt31CategoryIsTheEighteenthSitemapFile(): void {
		$this->assertEquals(
			'Inspanningsverplichting art 3.1 Woo',
			$this->wooCategories->sitemapFiles()['sitemapindex-diwoo-infocat018.xml']
		);
	}

	// ──────────────────────────────────────────────────────────
	// getObjectService (private, via reflection)
	// ──────────────────────────────────────────────────────────

	public function testGetObjectServiceAvailable(): void {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$method = $this->getPrivateMethod('getObjectService');
		$result = $method->invoke($this->service);

		$this->assertSame($objectService, $result);
	}

	public function testGetObjectServiceNotAvailable(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');

		$method = $this->getPrivateMethod('getObjectService');
		$method->invoke($this->service);
	}

	// ──────────────────────────────────────────────────────────
	// getFileService (private, via reflection)
	// ──────────────────────────────────────────────────────────

	public function testGetFileServiceAvailable(): void {
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\FileService')
			->willReturn($fileService);

		$method = $this->getPrivateMethod('getFileService');
		$result = $method->invoke($this->service);

		$this->assertSame($fileService, $result);
	}

	public function testGetFileServiceNotAvailable(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister FileService is not available.');

		$method = $this->getPrivateMethod('getFileService');
		$method->invoke($this->service);
	}

	// ──────────────────────────────────────────────────────────
	// resolveSitemapRequest (private, via reflection)
	// ──────────────────────────────────────────────────────────

	public function testIsValidSitemapRequestNoSettings(): void {
		$this->settingsService->method('getSettings')
			->willReturn([]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$method = $this->getPrivateMethod('resolveSitemapRequest');
		$result = $method->invoke($this->service, new SitemapRequest('test-catalog', 'sitemapindex-diwoo-infocat001.xml'), $objectService);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testIsValidSitemapRequestInvalidCategoryCode(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					['title' => 'woo', 'id' => 'reg-1', 'schemas' => []],
				],
			]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$method = $this->getPrivateMethod('resolveSitemapRequest');
		$result = $method->invoke($this->service, new SitemapRequest('test-catalog', 'invalid-category.xml'), $objectService);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testIsValidSitemapRequestValidRequest(): void {
		$schemaTitle = 'Wetten en algemeen verbindende voorschriften';

		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					[
						'title' => 'Woo',
						'id' => 'reg-woo',
						'schemas' => [
							['id' => 'sch-1', 'title' => $schemaTitle],
						],
					],
				],
				'configuration' => [
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
				],
			]);

		// Mock catalog object with getObject() and getSlug().
		$catalogObj = new class {
			public function getObject(): array {
				return ['schemas' => ['sch-1', 'sch-2']];
			}

			public function getSlug(): string {
				return 'my-catalog';
			}

			public function jsonSerialize(): array {
				return ['slug' => 'my-catalog'];
			}
		};

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => [$catalogObj]]);

		$method = $this->getPrivateMethod('resolveSitemapRequest');

		$request = new SitemapRequest('my-catalog', 'sitemapindex-diwoo-infocat001.xml');
		$result = $method->invoke($this->service, $request, $objectService);
		$schemaId = $request->schemaId;
		$registerId = $request->registerId;

		$this->assertNull($result);
		$this->assertEquals('sch-1', $schemaId);
		$this->assertEquals('reg-woo', $registerId);
	}

	public function testIsValidSitemapRequestEmptyCatalog(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					[
						'title' => 'Woo',
						'id' => 'reg-woo',
						'schemas' => [
							['id' => 'sch-1', 'title' => 'Wetten en algemeen verbindende voorschriften'],
						],
					],
				],
				'configuration' => [
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
				],
			]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => []]);

		$method = $this->getPrivateMethod('resolveSitemapRequest');
		$result = $method->invoke($this->service, new SitemapRequest('nonexistent-catalog', 'sitemapindex-diwoo-infocat001.xml'), $objectService);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testIsValidSitemapRequestSchemaNotInCatalog(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					[
						'title' => 'Woo',
						'id' => 'reg-woo',
						'schemas' => [
							['id' => 'sch-99', 'title' => 'Wetten en algemeen verbindende voorschriften'],
						],
					],
				],
				'configuration' => [
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
				],
			]);

		// Catalog that does not contain sch-99 in its schemas.
		$catalogObj = new class {
			public function getObject(): array {
				return ['schemas' => ['sch-1', 'sch-2']];
			}
		};

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => [$catalogObj]]);

		$method = $this->getPrivateMethod('resolveSitemapRequest');
		$result = $method->invoke($this->service, new SitemapRequest('my-catalog', 'sitemapindex-diwoo-infocat001.xml'), $objectService);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testIsValidSitemapRequestJaarplanTitleMapping(): void {
		// Category 012 = 'Jaarplannen en jaarverslagen'
		// The code maps 'Jaarplan of jaarverslag' to 'Jaarplannen en jaarverslagen'.
		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					[
						'title' => 'woo',
						'id' => 'reg-woo',
						'schemas' => [
							['id' => 'sch-12', 'title' => 'Jaarplan of jaarverslag'],
						],
					],
				],
				'configuration' => [
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
				],
			]);

		$catalogObj = new class {
			public function getObject(): array {
				return ['schemas' => ['sch-12']];
			}

			public function getSlug(): string {
				return 'test-slug';
			}
		};

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => [$catalogObj]]);

		$method = $this->getPrivateMethod('resolveSitemapRequest');

		$request = new SitemapRequest('test-slug', 'sitemapindex-diwoo-infocat012.xml');
		$result = $method->invoke($this->service, $request, $objectService);
		$schemaId = $request->schemaId;
		$registerId = $request->registerId;

		$this->assertNull($result);
		$this->assertEquals('sch-12', $schemaId);
	}

	// ──────────────────────────────────────────────────────────
	// mapDiwooDocument (private, via reflection)
	// ──────────────────────────────────────────────────────────

	public function testMapDiwooDocument(): void {
		// Fully value-list-bindable publication: category resolves to infocat014,
		// organisation carries a valid TOOI organisatie identifier, declared
		// handling type resolves through the DiWoo value list.
		$publication = [
			'id' => 'pub-1',
			'title' => '  Besluit op het Woo-verzoek  ',
			'category' => 'infocat014',
			'soortHandeling' => 'vaststelling',
			'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855',
			'@self' => [
				'created' => '2024-01-15 10:00:00',
				'updated' => '2024-06-20 14:30:00',
				'owner' => 'admin',
				'organisation' => 'org-uuid-123',
				'published' => '2024-03-01 08:00:00',
			],
		];

		$file = [
			'downloadUrl' => 'https://example.com/files/doc.pdf',
			'extension' => 'pdf',
			'published' => '2024-05-10 12:00:00',
			'owner' => 'file-owner',
		];

		$method = $this->getPrivateMethod('mapDiwooDocument');
		$result = $method->invoke($this->service, $publication, $file);

		$this->assertSame(['loc', 'lastmod', 'diwoo:Document'], array_keys($result));
		$this->assertSame('https://example.com/files/doc.pdf', $result['loc']);
		$this->assertSame('2024-05-10T12:00:00+00:00', $result['lastmod']);

		$doc = $result['diwoo:Document']['diwoo:DiWoo'];

		$this->assertArrayNotHasKey('loc', $doc);
		$this->assertArrayNotHasKey('lastmod', $doc);
		$this->assertEquals('2024-01-15', $doc['diwoo:creatiedatum']);
		$this->assertSame(['diwoo:officieleTitel' => 'Besluit op het Woo-verzoek'], $doc['diwoo:titelcollectie']);

		// Publisher @resource is bound to the TOOI organisatie identifier (WOO-TOOI-002).
		$this->assertEquals('https://identifier.overheid.nl/tooi/id/gemeente/gm0855', $doc['diwoo:publisher']['@resource']);
		$this->assertEquals('file-owner', $doc['diwoo:publisher']['#text']);

		// Format.
		$this->assertStringContainsString('PDF', $doc['diwoo:format']['@resource']);
		$this->assertEquals('pdf', $doc['diwoo:format']['#text']);

		// Classification bound to the official TOOI category URI (WOO-TOOI-001).
		$classification = $doc['diwoo:classificatiecollectie']['diwoo:informatiecategorieen']['diwoo:informatiecategorie'];
		$this->assertEquals('Woo-verzoeken en -besluiten', $classification['#text']);
		$this->assertEquals('https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532', $classification['@resource']);

		// Document handling — declared handling type resolves through the value list (WOO-TOOI-003).
		$handling = $doc['diwoo:documenthandelingen']['diwoo:documenthandeling'];
		$this->assertEquals('vaststelling', $handling['diwoo:soortHandeling']['#text']);
		$this->assertEquals('https://identifier.overheid.nl/tooi/def/thes/kern/c_641ecd76', $handling['diwoo:soortHandeling']['@resource']);
		$this->assertSame('2024-05-10T12:00:00+00:00', $handling['diwoo:atTime']);
	}

	/**
	 * A publication that names its organisation only by UUID gets the
	 * organisation's own tooiIdentifier as publisher, looked up once per
	 * organisation for the whole request (WOO-TOOI-002).
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testThePublisherIsLookedUpOncePerOrganisation(): void {
		$tooi = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855';
		$objectService = $this->environmentAwareDouble(\OCA\OpenRegister\Service\ObjectService::class, ['searchObjects']);
		$objectService->expects($this->once())
			->method('searchObjects')
			->with(['@self' => ['uuid' => 'org-uuid-123']])
			->willReturn([['tooiIdentifier' => $tooi]]);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($objectService);

		$file = ['downloadUrl' => 'https://example.com/files/doc.pdf', 'extension' => 'pdf', 'owner' => 'file-owner'];
		$method = $this->getPrivateMethod('mapDiwooDocument');
		foreach (['pub-a', 'pub-b'] as $id) {
			$publication = ['id' => $id, 'category' => 'infocat014', '@self' => ['created' => '2024-01-15 10:00:00', 'organisation' => 'org-uuid-123']];
			$doc = $method->invoke($this->service, $publication, $file)['diwoo:Document']['diwoo:DiWoo'];
			$this->assertSame($tooi, $doc['diwoo:publisher']['@resource'], $id);
		}
	}

	/**
	 * diwoo:atTime falls back to the publication's own publicationDate (RBAC model)
	 * when the file has no published timestamp. The removed object-level
	 * @self.published predicate is always empty for magic-mapped publications and
	 * MUST NOT be used.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testMapDiwooDocumentAtTimeFallsBackToPublicatiedatum(): void {
		$publication = [
			'id' => 'pub-3',
			'publicationDate' => '2024-04-02 09:30:00',
			// A leftover (dead) @self.published MUST be ignored.
			'@self' => ['published' => '2099-01-01 00:00:00'],
		];

		$file = [
			'downloadUrl' => 'https://example.com/files/besluit.pdf',
			'extension' => 'pdf',
			// No 'published' key on the file -> must fall back to publicationDate.
		];

		$method = $this->getPrivateMethod('mapDiwooDocument');
		$result = $method->invoke($this->service, $publication, $file);

		$handling = $result['diwoo:Document']['diwoo:DiWoo']['diwoo:documenthandelingen']['diwoo:documenthandeling'];
		$this->assertSame('2024-04-02T09:30:00+00:00', $handling['diwoo:atTime']);
	}

	public function testMapDiwooDocumentOmitsUnresolvedAxesAndReports(): void {
		// No organisation TOOI identifier, no resolvable category → both axes are
		// OMITTED (never leaked as a literal @resource) and reported (WOO-TOOI-004).
		$publication = [
			'id' => 'pub-2',
			'@self' => [],
		];

		$file = [
			'downloadUrl' => 'https://example.com/files/report.docx',
			'extension' => 'docx',
		];

		$violations = [];
		$method = $this->getPrivateMethod('mapDiwooDocument');
		$result = $method->invokeArgs($this->service, [$publication, $file, &$violations]);

		$doc = $result['diwoo:Document']['diwoo:DiWoo'];

		// Publisher @resource omitted (no TOOI id); human-readable #text still present.
		$this->assertArrayNotHasKey('@resource', $doc['diwoo:publisher']);

		// Unresolved informatiecategorie omitted entirely — no free-text @resource.
		$this->assertArrayNotHasKey('diwoo:classificatiecollectie', $doc);

		// soortHandeling still binds to the default value-list member.
		$handling = $doc['diwoo:documenthandelingen']['diwoo:documenthandeling'];
		$this->assertEquals('ontvangst', $handling['diwoo:soortHandeling']['#text']);
		$this->assertEquals('https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535', $handling['diwoo:soortHandeling']['@resource']);

		// Format should be uppercase extension in URI.
		$this->assertStringContainsString('DOCX', $doc['diwoo:format']['@resource']);
		$this->assertEquals('docx', $doc['diwoo:format']['#text']);

		// Both unresolved axes reported by the validator.
		$axes = array_column($violations, 'axis');
		$this->assertContains('publisher', $axes);
		$this->assertContains('informatiecategorie', $axes);
		$this->assertContains('officieleTitel', $axes);
		$this->assertArrayNotHasKey('diwoo:titelcollectie', $doc);
	}

	// ──────────────────────────────────────────────────────────
	// buildSitemapIndex
	// ──────────────────────────────────────────────────────────

	public function testBuildSitemapIndexInvalidCategory(): void {
		$this->setupAppManagerAndContainer();

		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					['title' => 'Woo', 'id' => 'reg-1', 'schemas' => []],
				],
			]);

		$result = $this->service->buildSitemapIndex('catalog-slug', 'invalid-category.xml');

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testBuildSitemapIndexEmptyResults(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) {
				// Catalog search.
				if (isset($query['slug'])) {
					return ['results' => [$this->createCatalogObj(['sch-1'])]];
				}
				// Publications search - empty.
				return ['results' => [], 'total' => 0];
			});

		$this->setupAppManagerAndContainer($objectService);

		$result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testBuildSitemapIndexValidRequest(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$pubObject = $this->createSerializableObject([
			'@self' => ['updated' => '2024-06-15 10:00:00'],
		]);

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$callCount = 0;
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($pubObject, $catalogObj, &$callCount) {
				$callCount++;
				// Catalog search.
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				// Publications search.
				return [
					'results' => [$pubObject],
					'total' => 1,
					'next' => null,
				];
				return null;
			});

		$this->setupAppManagerAndContainer($objectService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com');

		$result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testBuildSitemapIndexWithPagination(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$pubObject1 = $this->createSerializableObject([
			'@self' => ['updated' => '2024-06-15 10:00:00'],
		]);
		$pubObject2 = $this->createSerializableObject([
			'@self' => ['updated' => '2024-05-10 08:00:00'],
		]);

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$pageCallCount = 0;
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($pubObject1, $pubObject2, $catalogObj, &$pageCallCount) {
				// Catalog search.
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}

				// Publications search with pagination.
				$pageCallCount++;
				if ($pageCallCount === 1) {
					return [
						'results' => [$pubObject1],
						'total' => 2,
						'next' => 'page2-cursor',
					];
				}

				// Second page.
				return [
					'results' => [$pubObject2],
					'total' => 2,
					'next' => null,
				];
			});

		$this->setupAppManagerAndContainer($objectService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com');

		$result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	// ──────────────────────────────────────────────────────────
	// buildSitemap
	// ──────────────────────────────────────────────────────────

	public function testBuildSitemapValidRequest(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$pubObject = $this->createSerializableObject([
			'id' => 'pub-1',
			'tooiCategorieNaam' => 'Test Category',
			'tooiCategorieUri' => 'https://tooi/cat',
			'@self' => [
				'created' => '2024-01-01',
				'updated' => '2024-06-01',
				'owner' => 'admin',
				'organisation' => 'https://org',
			],
		]);

		$fileData = [
			'results' => [
				[
					'downloadUrl' => 'https://example.com/file.pdf',
					'extension' => 'pdf',
					'published' => '2024-03-01',
				],
			],
		];

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')
			->willReturn(['some-file-data']);
		$fileService->method('formatFiles')
			->willReturn($fileData);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($pubObject, $catalogObj) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				return ['results' => [$pubObject]];
			});

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->willReturnCallback(function ($id) use ($objectService, $fileService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}
				return null;
			});

		$result = $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	/**
	 * The documents XML of one page with three files, the organisation looked up by uuid.
	 *
	 * @param array<int, mixed> $calls The organisation lookups, recorded by parameter name.
	 *
	 * @return string
	 */
	private function documentsPage(array &$calls): string {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');
		$catalogObj = $this->createCatalogObj(['sch-1']);
		$pubObject = $this->createSerializableObject([
			'id' => 'pub-1',
			'title' => 'Besluit & bijlagen <2024>',
			'publicationDate' => '2024-02-02 08:00:00',
			'category' => 'infocat014',
			'soortHandeling' => 'vaststelling',
			'@self' => ['created' => '2024-01-01', 'updated' => '2024-06-01', 'owner' => 'admin', 'organisation' => 'org-uuid-1'],
		]);
		$files = ['results' => [
			['downloadUrl' => 'https://example.com/a.pdf', 'extension' => 'pdf', 'published' => '2024-03-01'],
			['extension' => 'txt'],
			['downloadUrl' => 'https://example.com/b.odt', 'extension' => 'odt', 'owner' => 'file-owner'],
		]];
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')->willReturn(['some-file-data']);
		$fileService->method('formatFiles')->willReturn($files);

		$objectService = $this->environmentAwareDouble(\OCA\OpenRegister\Service\ObjectService::class, ['searchObjectsPaginated', 'searchObjects']);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = []) use ($pubObject, $catalogObj): array {
				if (isset($query['slug']) === true) {
					return ['results' => [$catalogObj]];
				}

				return ['results' => [$pubObject]];
			}
		);
		$objectService->method('searchObjects')->willReturnCallback(
			function (...$args) use (&$calls): array {
				// Recorded by parameter name, so the stub and the real ObjectService read the same.
				$names = ['query', '_rbac', '_multitenancy'];
				if (method_exists(\OCA\OpenRegister\Service\ObjectService::class, 'searchObjects') === true) {
					$names = array_map(
						static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
						(new \ReflectionMethod(\OCA\OpenRegister\Service\ObjectService::class, 'searchObjects'))->getParameters()
					);
				}

				$named = array_combine(array_slice($names, 0, count($args)), array_slice($args, 0, count($names)));
				$calls[] = array_intersect_key($named, array_flip(['query', '_rbac', '_multitenancy']));
				return [$this->createSerializableObject(['tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855'])];
			}
		);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturnCallback(
			static fn ($id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objectService,
				'OCA\OpenRegister\Service\FileService' => $fileService,
				default => null,
			}
		);

		return $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1)->render();
	}

	/**
	 * The full documents XML of a page matches the recording: the root attributes,
	 * one document per downloadable file, and the publisher looked up by
	 * organisation uuid (outside RBAC) from an entity row.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testBuildSitemapDocumentsMatchTheRecording(): void {
		$calls = [];
		$xml = $this->documentsPage(calls: $calls);
		$actual = json_decode((string)json_encode(['data' => $xml, 'lookups' => $calls]), true);
		$fixture = __DIR__ . '/fixtures/sitemap-documents-golden.json';
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}

	/**
	 * The XSD errors of every diwoo:Document in a sitemap, one list per document,
	 * validated against the vendored official DiWoo 0.9.8 XSD (fixtures/diwoo-0.9.8/SOURCE.md).
	 *
	 * @param string $xml The sitemap XML.
	 *
	 * @return array<int, array<int, string>>
	 */
	private static function diwooXsdErrors(string $xml): array {
		$dir = __DIR__ . '/fixtures/diwoo-0.9.8';
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		libxml_set_external_entity_loader(
			static fn (?string $public, string $system): ?string => match ($system) {
				'https://www.nationaalarchief.nl/mdto/MDTO-XML1.0.1.xsd' => $dir . '/MDTO-XML1.0.1.xsd',
				default => $system,
			}
		);
		$previous = libxml_use_internal_errors(true);
		$errors = [];
		try {
			foreach ($dom->getElementsByTagNameNS(StandardsVersionService::DIWOO_NAMESPACE, 'Document') as $index => $document) {
				$one = new \DOMDocument();
				$one->appendChild($one->importNode($document, true));
				$one->loadXML((string)$one->saveXML());
				$one->schemaValidate($dir . '/diwoo-metadata.xsd');
				$errors[$index] = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
				libxml_clear_errors();
			}
		} finally {
			libxml_use_internal_errors($previous);
			libxml_set_external_entity_loader(null);
		}

		return $errors;
	}

	/**
	 * Fail unless every file in the fixture directory has its published sha256.
	 *
	 * @param array<string, string> $hashes The file names and their sha256.
	 *
	 * @return void
	 */
	private function assertVerbatimCopies(array $hashes): void {
		foreach ($hashes as $file => $hash) {
			$this->assertSame($hash, hash_file('sha256', __DIR__ . '/fixtures/diwoo-0.9.8/' . $file), $file . ' is not the published file');
		}
	}

	/**
	 * Every DiWoo document of a rendered page validates against the official DiWoo 0.9.8 XSD.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testEveryDocumentOfAPageValidatesAgainstTheDiwooXsd(): void {
		$this->assertVerbatimCopies([
			'diwoo-metadata.xsd' => '0a332f65c98f5bb2b4fb64c52c2f72867966a6e5bf1a5a5ea3870716fc6ea212',
			'diwoo-metadata-lijsten.xsd' => 'c17fd0daa9eb37c44c774ac73271679a8fc2189805823dcfba6a86c2fd42b308',
			'MDTO-XML1.0.1.xsd' => 'edad88821a1023a9eafca0fa399ee7735ef03481aa186e07bbdaabdc123def34',
		]);

		$calls = [];
		$this->assertSame([[], []], self::diwooXsdErrors($this->documentsPage(calls: $calls)));
	}

	/**
	 * The harness accepts what the standard accepts: the documents of the standard's
	 * own example sitemaps validate, apart from the fictitious municipalities gm9999
	 * and gm9998 they publish under, which the organisation list does not hold.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testTheOfficialExampleSitemapsValidate(): void {
		$examples = [
			'sitemap-alleenverplicht.xml' => 'a7a0354d60667de9fc85567d379b67d407ce04a78e44d4107530828e33928b32',
			'sitemap-uitgebreid.xml' => '2224a9d841e54dff52447d9ac9c65c893f70bdb0d5c665dfb675d4c4881c0b61',
		];
		$this->assertVerbatimCopies($examples);
		foreach (array_keys($examples) as $file) {
			$errors = self::diwooXsdErrors((string)file_get_contents(__DIR__ . '/fixtures/diwoo-0.9.8/' . $file));
			$this->assertNotSame([], $errors, $file . ' holds no document');
			$this->assertNotSame([], array_merge(...$errors), $file . ': the fictitious publisher must be refused, or nothing was validated');
			foreach (array_merge(...$errors) as $error) {
				$this->assertMatchesRegularExpression("~attribute 'resource': 'https://identifier\\.overheid\\.nl/tooi/id/gemeente/gm999[89]' is not a valid value~", $error, $file);
			}
		}
	}

	/**
	 * A page is a sitemaps.org urlset; each url holds loc, lastmod and the document, in that order.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testEachUrlHoldsLocLastmodAndTheDocumentInThatOrder(): void {
		$calls = [];
		$dom = new \DOMDocument();
		$dom->loadXML($this->documentsPage(calls: $calls));
		$root = $dom->documentElement;
		$this->assertSame(['urlset', StandardsVersionService::SITEMAP_NAMESPACE], [$root->localName, $root->namespaceURI]);

		$urls = [];
		foreach ($root->childNodes as $url) {
			if ($url instanceof \DOMElement === false) {
				continue;
			}

			$children = [];
			foreach ($url->childNodes as $child) {
				if ($child instanceof \DOMElement === true) {
					$children[] = [$child->namespaceURI, $child->localName, $child->localName === 'Document' ? '' : $child->textContent];
				}
			}

			$urls[] = [$url->namespaceURI, $url->localName, $children];
		}

		$sitemap = StandardsVersionService::SITEMAP_NAMESPACE;
		$diwoo = StandardsVersionService::DIWOO_NAMESPACE;
		$this->assertSame([
			[$sitemap, 'url', [[$sitemap, 'loc', 'https://example.com/a.pdf'], [$sitemap, 'lastmod', '2024-03-01T00:00:00+00:00'], [$diwoo, 'Document', '']]],
			[$sitemap, 'url', [[$sitemap, 'loc', 'https://example.com/b.odt'], [$sitemap, 'lastmod', '2024-06-01T00:00:00+00:00'], [$diwoo, 'Document', '']]],
		], $urls);
		$this->assertSame(0, $dom->getElementsByTagNameNS($diwoo, 'DiWoo')->item(0)->getElementsByTagNameNS($sitemap, '*')->length, 'diwoo:DiWoo holds no sitemap element');
	}

	/**
	 * lastmod and atTime are xs:dateTime values in UTC, the same in every server time zone.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testDatesAreWrittenAsUtcDateTimes(): void {
		$method = $this->getPrivateMethod('mapDiwooDocument');
		$zone = date_default_timezone_get();
		$cases = [
			'date only' => ['2024-03-01', '2024-03-01T00:00:00+00:00'],
			'local time' => ['2024-02-02 08:00:00', '2024-02-02T08:00:00+00:00'],
			'with offset' => ['2024-03-01T10:00:00+02:00', '2024-03-01T08:00:00+00:00'],
			'unparseable' => ['not a date', '1970-01-01T00:00:00+00:00'],
			'not a string' => [20240301, '1970-01-01T00:00:00+00:00'],
		];
		try {
			foreach (['UTC', 'Europe/Amsterdam', 'Pacific/Kiritimati'] as $serverZone) {
				date_default_timezone_set($serverZone);
				foreach ($cases as $name => [$stored, $expected]) {
					$entry = $method->invoke($this->service, ['id' => 'p', 'soortHandeling' => 'vaststelling'], ['downloadUrl' => 'https://example.com/a.pdf', 'extension' => 'pdf', 'published' => $stored]);
					$this->assertSame($expected, $entry['lastmod'], $serverZone . ' ' . $name);
					$this->assertSame($expected, $entry['diwoo:Document']['diwoo:DiWoo']['diwoo:documenthandelingen']['diwoo:documenthandeling']['diwoo:atTime'], $serverZone . ' ' . $name);
				}
			}
		} finally {
			date_default_timezone_set($zone);
		}
	}

	/**
	 * A publication without a title is reported as officieleTitel, and its document stays on the page.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testAPublicationWithoutATitleIsReported(): void {
		$file = ['downloadUrl' => 'https://example.com/a.pdf', 'extension' => 'pdf'];
		foreach (['missing' => null, 'blank' => '   ', 'not text' => ['nl' => 'Besluit']] as $name => $title) {
			$publication = ['id' => 'p', 'category' => 'infocat014', '@self' => ['files' => [$file]]];
			if ($title !== null) {
				$publication['title'] = $title;
			}

			$violations = $this->service->collectDiwooViolations([$publication]);
			$this->assertContains(['documentLoc' => 'https://example.com/a.pdf', 'axis' => 'officieleTitel', 'reason' => 'publication has no title'], $violations, $name);
		}

		$titled = ['id' => 'p', 'title' => 'Besluit', 'category' => 'infocat014', '@self' => ['files' => [$file]]];
		$this->assertNotContains('officieleTitel', array_column($this->service->collectDiwooViolations([$titled]), 'axis'));
	}

	/**
	 * The requested page reaches the publication search, and of two catalogs found the first is used.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testThePageReachesTheSearchAndTheFirstCatalogIsUsed(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');
		$first = $this->createCatalogObj(['sch-1']);
		$second = $this->createCatalogObj(['sch-other']);
		$queries = [];
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = []) use (&$queries, $first, $second): array {
				$queries[] = $query;
				if (isset($query['slug']) === true) {
					return ['results' => [$first, $second]];
				}

				return ['results' => []];
			}
		);
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturnCallback(
			static fn ($id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objectService,
				'OCA\OpenRegister\Service\FileService' => $fileService,
				default => null,
			}
		);

		$this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 2);

		$searches = array_values(array_filter($queries, static fn (array $query): bool => isset($query['slug']) === false));
		$this->assertNotSame([], $searches, 'The publications were never searched.');
		foreach ($searches as $query) {
			$this->assertSame(2, $query['_page']);
			$this->assertSame(['register' => 'reg-woo', 'schema' => 'sch-1'], $query['@self'], 'The first catalog scopes the search.');
		}
	}

	/**
	 * A page without downloadable files is a urlset without url entries.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function testAPageWithoutFilesIsAnEmptyUrlset(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$pubObject = $this->createSerializableObject([
			'id' => 'pub-2',
			'@self' => [
				'created' => '2024-01-01',
				'updated' => '2024-06-01',
			],
		]);

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')
			->willReturn([]);
		$fileService->method('formatFiles')
			->willReturn(['results' => []]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($pubObject, $catalogObj) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				return ['results' => [$pubObject]];
			});

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->willReturnCallback(function ($id) use ($objectService, $fileService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}
				return null;
			});

		$result = $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1);

		$this->assertInstanceOf(XMLResponse::class, $result);
		$dom = new \DOMDocument();
		$dom->loadXML($result->render());
		$this->assertSame(['urlset', StandardsVersionService::SITEMAP_NAMESPACE], [$dom->documentElement->localName, $dom->documentElement->namespaceURI]);
		$this->assertSame(0, $dom->documentElement->getElementsByTagName('*')->length);
	}

	public function testBuildSitemapWithFileMissingDownloadUrl(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$pubObject = $this->createSerializableObject([
			'id' => 'pub-3',
			'@self' => [
				'created' => '2024-01-01',
				'updated' => '2024-06-01',
			],
		]);

		// File without downloadUrl should be skipped.
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')
			->willReturn(['some-file-data']);
		$fileService->method('formatFiles')
			->willReturn([
				'results' => [
					['extension' => 'pdf'],
				],
			]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($pubObject, $catalogObj) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				return ['results' => [$pubObject]];
			});

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->willReturnCallback(function ($id) use ($objectService, $fileService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}
				return null;
			});

		$result = $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	public function testBuildSitemapEmptyPublications(): void {
		$this->setupValidSitemapContext('reg-woo', 'sch-1');

		$catalogObj = $this->createCatalogObj(['sch-1']);

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($catalogObj) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				return ['results' => []];
			});

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->willReturnCallback(function ($id) use ($objectService, $fileService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}
				return null;
			});

		$result = $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1);

		$this->assertInstanceOf(XMLResponse::class, $result);
	}

	// ──────────────────────────────────────────────────────────
	// WOO-581 review f3 — the read-rule guard on the sitemap
	// ──────────────────────────────────────────────────────────

	/**
	 * Wire a valid sitemap request whose category schema is `41`, and count the
	 * publication searches (the catalog lookup is not one).
	 *
	 * @param int $publicationSearches Incremented per publication search.
	 *
	 * @return void
	 */
	private function wireGuardedSitemap(int &$publicationSearches): void {
		$this->setupValidSitemapContext('7', '41');
		$catalogObj = $this->createCatalogObj([41]);
		$pubObject = $this->createSerializableObject(['id' => 'pub-1', '@self' => ['updated' => '2024-06-01']]);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query = []) use ($catalogObj, $pubObject, &$publicationSearches) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				$publicationSearches++;
				return ['results' => [$pubObject], 'total' => 1, 'next' => null];
			});

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('formatFiles')->willReturn(['results' => []]);

		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturnCallback(
			static fn ($id) => $id === 'OCA\OpenRegister\Service\FileService' ? $fileService : $objectService
		);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://example.com');
	}

	/**
	 * The sitemap admits its category schema on `$catalog['schemas']` membership
	 * and then searched it with `_rbac: true`, unguarded — for a schema without
	 * an `authorization` block that published every non-private row, documents
	 * included, to the national Woo index. A dropped schema is now an empty,
	 * valid sitemap index, and no publication search runs.
	 */
	public function testBuildSitemapIndexIsEmptyWhenTheGuardDropsTheSchema(): void {
		$publicationSearches = 0;
		$this->wireGuardedSitemap($publicationSearches);
		$this->guard = static fn (array $schemaIds): array => [];

		$result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertSame(200, $result->getStatus());
		$this->assertStringNotContainsString('<sitemap>', $result->render());
		$this->assertSame(0, $publicationSearches);
	}

	public function testBuildSitemapIsEmptyWhenTheGuardDropsTheSchema(): void {
		$publicationSearches = 0;
		$this->wireGuardedSitemap($publicationSearches);
		$this->guard = static fn (array $schemaIds): array => [];

		$result = $this->service->buildSitemap('my-catalog', 'sitemapindex-diwoo-infocat001.xml', 1);

		$this->assertSame(200, $result->getStatus());
		$this->assertStringNotContainsString('<diwoo:Document>', $result->render());
		$this->assertSame(0, $publicationSearches);
	}

	/**
	 * Negative control: the guard keeps the schema → the sitemap is built as
	 * before, so the guard cannot be why a working Woo-index feed goes quiet.
	 */
	public function testBuildSitemapIndexIsUnchangedWhenTheGuardKeepsTheSchema(): void {
		$publicationSearches = 0;
		$this->wireGuardedSitemap($publicationSearches);

		$result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertStringContainsString('<sitemap>', $result->render());
		$this->assertSame(1, $publicationSearches);
	}

	// ──────────────────────────────────────────────────────────
	// woo-publication-category (REQ-WPC-002)
	// ──────────────────────────────────────────────────────────

	/**
	 * Wire a catalogue that holds register `reg-pub` with the given schemas, the
	 * given settings registers, and a publication store answered by $answer.
	 *
	 * @param array $settingsRegisters The availableRegisters setting.
	 * @param array $catalogSchemas The catalogue's schemas.
	 * @param \Closure $answer fn(array $query): array of publication rows.
	 * @param array $queries Every publication query, collected.
	 */
	private function wireCategoryCatalog(array $settingsRegisters, array $catalogSchemas, \Closure $answer, array &$queries): void {
		$this->settingsService->method('getSettings')->willReturn([
			'availableRegisters' => $settingsRegisters,
			'configuration' => ['catalog_register' => 'cat-reg', 'catalog_schema' => 'cat-sch'],
		]);
		$catalogObj = new class($catalogSchemas) {
			public function __construct(private array $schemas) {
			}

			public function getObject(): array {
				return ['registers' => ['reg-pub'], 'schemas' => $this->schemas];
			}

			public function getSlug(): string {
				return 'woo';
			}
		};

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query = []) use ($catalogObj, $answer, &$queries) {
				if (isset($query['slug'])) {
					return ['results' => [$catalogObj]];
				}
				$queries[] = $query;
				$rows = array_map(fn (array $row) => $this->createSerializableObject($row), $answer($query));
				return ['results' => $rows, 'total' => count($rows), 'next' => null];
			}
		);

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')->willReturnCallback(static fn ($object) => [$object]);
		$fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files) => ['results' => [['downloadUrl' => 'https://example.com/' . $files[0] . '.pdf', 'extension' => 'pdf']]]
		);

		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturnCallback(
			static fn ($id) => $id === 'OCA\OpenRegister\Service\FileService' ? $fileService : $objectService
		);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://example.com');
	}

	/**
	 * Three publications, two filed under infocat012 and one under infocat004,
	 * in a catalogue on an instance WITHOUT a hand-made `woo` register.
	 *
	 * @return \Closure The store: filters on the query's wooCategory.
	 */
	private function threeFiledPublications(): \Closure {
		$publications = [
			['id' => 'jaarverslag-2024', 'wooCategory' => 'infocat012', '@self' => ['updated' => '2025-01-02']],
			['id' => 'jaarplan-2025', 'wooCategory' => 'infocat012', '@self' => ['updated' => '2025-01-01']],
			['id' => 'organogram', 'wooCategory' => 'infocat004', '@self' => ['updated' => '2025-01-03']],
		];
		return static fn (array $query): array => array_values(array_filter(
			$publications,
			static fn (array $p): bool => isset($query['wooCategory']) === false || $p['wooCategory'] === $query['wooCategory']
		));
	}

	/**
	 * REQ-WPC-002 scenario "The harvester reads one category": the sitemap of
	 * infocat012 lists exactly the two publications filed under it.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testTheCategorySitemapListsExactlyThePublicationsFiledUnderIt(): void {
		$queries = [];
		$this->wireCategoryCatalog([], ['sch-pub'], $this->threeFiledPublications(), $queries);

		$result = $this->service->buildSitemap('woo', 'sitemapindex-diwoo-infocat012.xml', 1);

		$this->assertSame(200, $result->getStatus());
		$xml = $result->render();
		$this->assertSame(2, substr_count($xml, '<diwoo:Document>'));
		$this->assertStringContainsString('jaarverslag-2024.pdf', $xml);
		$this->assertStringContainsString('jaarplan-2025.pdf', $xml);
		$this->assertStringNotContainsString('organogram.pdf', $xml);
		$this->assertStringContainsString('Jaarplannen en jaarverslagen', $xml);

		$this->assertCount(1, $queries);
		$this->assertSame('infocat012', $queries[0]['wooCategory']);
		$this->assertSame('reg-pub', $queries[0]['@self']['register']);
		$this->assertSame('sch-pub', $queries[0]['@self']['schema']);
	}

	/**
	 * The sitemap index of a category is built from the filed publications,
	 * without a `woo` register.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testTheCategorySitemapIndexNeedsNoWooRegister(): void {
		$queries = [];
		$this->wireCategoryCatalog([], ['sch-pub'], $this->threeFiledPublications(), $queries);

		$result = $this->service->buildSitemapIndex('woo', 'sitemapindex-diwoo-infocat004.xml');

		$this->assertSame(200, $result->getStatus());
		$xml = $result->render();
		$this->assertSame(1, substr_count($xml, '<sitemap>'));
		$this->assertStringContainsString('sitemaps/sitemapindex-diwoo-infocat004.xml/publications?page=1', $xml);
	}

	/**
	 * On an instance that still runs a `woo` register, its title-matched schema
	 * contributes its rows as well; a row found both ways is listed once.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testTheLegacyWooRegisterStillContributesItsRows(): void {
		$queries = [];
		$filed = $this->threeFiledPublications();
		$answer = static function (array $query) use ($filed): array {
			if (($query['@self']['schema'] ?? null) === 'sch-12' && isset($query['wooCategory']) === false) {
				return [
					['id' => 'oud-jaarverslag', '@self' => ['updated' => '2020-01-01']],
					['id' => 'jaarverslag-2024', 'wooCategory' => 'infocat012', '@self' => ['updated' => '2025-01-02']],
				];
			}
			return $filed($query);
		};
		$this->wireCategoryCatalog(
			[['title' => 'woo', 'id' => 'reg-woo', 'schemas' => [['id' => 'sch-12', 'title' => 'Jaarplan of jaarverslag']]]],
			['sch-pub', 'sch-12'],
			$answer,
			$queries
		);

		$xml = $this->service->buildSitemap('woo', 'sitemapindex-diwoo-infocat012.xml', 1)->render();

		$this->assertSame(3, substr_count($xml, '<diwoo:Document>'));
		$this->assertStringContainsString('oud-jaarverslag.pdf', $xml);
		$this->assertStringNotContainsString('organogram.pdf', $xml);
	}

	/**
	 * The service answers with the file names the registry serves (REQ-WIC-001).
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function testTheServiceAnswersWithTheRegistrysSitemapFiles(): void {
		$this->assertSame($this->wooCategories->sitemapFiles(), $this->service->sitemapFiles());
	}

	/**
	 * A category that names its schemas restricts its own sitemap to them, and the
	 * waardelijst members, which name none, keep searching the whole catalogue
	 * scope (REQ-WIC-003).
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function testACategoryNamingItsSchemasRestrictsItsSitemapToThem(): void {
		$queries = [];
		$this->wireCategoryCatalog(
			[
				[
					'title' => 'publication',
					'id' => 'reg-pub',
					'schemas' => [
						['id' => 'sch-pub', 'slug' => 'publication'],
						['id' => 'sch-tender', 'slug' => 'tender'],
					],
				],
			],
			['sch-pub', 'sch-tender'],
			static fn (array $query): array => [['id' => 'aanbesteding-1', '@self' => ['updated' => '2026-01-01']]],
			$queries
		);

		$service = $this->serviceWithLocalCategory(
			['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010', 'schemas' => ['tender']]
		);

		$result = $service->buildSitemap('woo', 'sitemapindex-diwoo-aanbestedingen.xml', 1);

		$this->assertSame(200, $result->getStatus());
		$this->assertCount(1, $queries);
		$this->assertSame('aanbestedingen', $queries[0]['wooCategory']);
		$this->assertSame('sch-tender', $queries[0]['@self']['schema'], 'The named schema is not the one searched.');
	}

	/**
	 * A category that names no schemas searches the catalogue's whole scope.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function testACategoryNamingNoSchemasSearchesTheWholeScope(): void {
		$queries = [];
		$this->wireCategoryCatalog(
			[
				[
					'title' => 'publication',
					'id' => 'reg-pub',
					'schemas' => [
						['id' => 'sch-pub', 'slug' => 'publication'],
						['id' => 'sch-tender', 'slug' => 'tender'],
					],
				],
			],
			['sch-pub', 'sch-tender'],
			static fn (array $query): array => [],
			$queries
		);

		$service = $this->serviceWithLocalCategory(
			['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010']
		);

		$service->buildSitemap('woo', 'sitemapindex-diwoo-aanbestedingen.xml', 1);

		$this->assertCount(1, $queries);
		$this->assertSame(['sch-pub', 'sch-tender'], $queries[0]['@self']['schema']);
	}

	/**
	 * A named slug no register declares narrows the search to nothing rather than
	 * widening it back to the whole catalogue.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function testASchemaSlugNoRegisterDeclaresListsNothing(): void {
		$queries = [];
		$this->wireCategoryCatalog(
			[['title' => 'publication', 'id' => 'reg-pub', 'schemas' => [['id' => 'sch-pub', 'slug' => 'publication']]]],
			['sch-pub'],
			static fn (array $query): array => [['id' => 'anything', '@self' => ['updated' => '2026-01-01']]],
			$queries
		);

		$service = $this->serviceWithLocalCategory(
			['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010', 'schemas' => ['tender']]
		);

		$result = $service->buildSitemap('woo', 'sitemapindex-diwoo-aanbestedingen.xml', 1);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame([], $queries, 'A slug nothing declares still produced a search.');
		$this->assertStringNotContainsString('<diwoo:Document>', $result->render());
	}

	/**
	 * A sitemap service whose registry holds one stored local category.
	 *
	 * The row is handed in through a mocked OpenRegister object service, the way the
	 * registry reads it in production.
	 *
	 * @param array<string, mixed> $stored The stored category.
	 *
	 * @return SitemapService The service.
	 */
	private function serviceWithLocalCategory(array $stored): SitemapService {
		$row = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$row->method('jsonSerialize')->willReturn($stored);

		$schema = $this->environmentAwareDouble(\OCA\OpenRegister\Db\Schema::class, ['getId']);
		$schema->method('getId')->willReturn(42);
		$schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => [$row]]);

		$registrySettings = $this->createMock(SettingsService::class);
		$registrySettings->method('getObjectService')->willReturn($objectService);
		$registrySettings->method('getSchemaMapper')->willReturn($schemaMapper);

		$tooi = new \OCA\OpenCatalogi\Service\TooiVocabularyService();

		return new SitemapService(
			new OpenRegisterLookup($this->container, $this->appManager),
			$this->settingsService,
			new InstanceSettings($this->urlGenerator, $this->config),
			$tooi,
			$this->queryService,
			new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
				$tooi,
				$registrySettings,
				$this->createMock(\Psr\Log\LoggerInterface::class),
				new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission($tooi),
			)
		);
	}

	/**
	 * mapDiwooDocument() reads wooCategory before the older category fields.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testTheDiwooCategoryComesFromWooCategoryFirst(): void {
		$publication = [
			'id' => 'pub-1',
			'wooCategory' => 'infocat012',
			'category' => 'infocat010',
			'tooiCategorieNaam' => 'Adviezen',
			'@self' => [],
		];
		$file = ['downloadUrl' => 'https://example.com/a.pdf', 'extension' => 'pdf'];

		$result = $this->getPrivateMethod('mapDiwooDocument')->invoke($this->service, $publication, $file);

		$category = $result['diwoo:Document']['diwoo:DiWoo']['diwoo:classificatiecollectie']['diwoo:informatiecategorieen']['diwoo:informatiecategorie'];
		$this->assertSame('Jaarplannen en jaarverslagen', $category['#text']);
	}

	// ──────────────────────────────────────────────────────────
	// Operator-tunable page size
	// ──────────────────────────────────────────────────────────

	public function testMaxPerPageDefaultsTo1000(): void {
		// setUp wires getValueInt to return the passed default.
		$method = $this->getPrivateMethod('getMaxPerPage');
		$this->assertSame(1000, $method->invoke($this->service));
	}

	public function testMaxPerPageHonoursConfigOverride(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')
			->willReturnMap([['opencatalogi', 'sitemap_max_per_page', 1000, 250]]);

		$service = new SitemapService(
			new OpenRegisterLookup($this->container, $this->appManager),
			$this->settingsService,
			new InstanceSettings($this->urlGenerator, $config),
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->queryService,
			$this->wooCategories
		);

		$reflection = new \ReflectionClass($service);
		$method = $reflection->getMethod('getMaxPerPage');
		$method->setAccessible(true);
		$this->assertSame(250, $method->invoke($service));
	}

	public function testMaxPerPageClampsInvalidValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(0);

		$service = new SitemapService(
			new OpenRegisterLookup($this->container, $this->appManager),
			$this->settingsService,
			new InstanceSettings($this->urlGenerator, $config),
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->queryService,
			$this->wooCategories
		);

		$reflection = new \ReflectionClass($service);
		$method = $reflection->getMethod('getMaxPerPage');
		$method->setAccessible(true);
		$this->assertSame(1, $method->invoke($service));
	}

	// ──────────────────────────────────────────────────────────
	// Helper methods
	// ──────────────────────────────────────────────────────────

	/**
	 * Get a private method via reflection.
	 */
	private function getPrivateMethod(string $methodName): \ReflectionMethod {
		$reflection = new \ReflectionClass($this->service);
		$method = $reflection->getMethod($methodName);
		$method->setAccessible(true);

		return $method;
	}

	/**
	 * Create a serializable object mock.
	 */
	private function createSerializableObject(array $data): object {
		return new class($data) {
			private array $data;

			public function __construct(array $data) {
				$this->data = $data;
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}

	/**
	 * Create a catalog object mock with getObject() and getSlug().
	 */
	private function createCatalogObj(array $schemas): object {
		return new class($schemas) {
			private array $schemas;

			public function __construct(array $schemas) {
				$this->schemas = $schemas;
			}

			public function getObject(): array {
				return ['schemas' => $this->schemas];
			}

			public function getSlug(): string {
				return 'my-catalog';
			}

			public function jsonSerialize(): array {
				return ['slug' => 'my-catalog', 'schemas' => $this->schemas];
			}
		};
	}

	/**
	 * Setup settings service with valid Woo register and schema.
	 */
	private function setupValidSitemapContext(string $registerId, string $schemaId): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'availableRegisters' => [
					[
						'title' => 'Woo',
						'id' => $registerId,
						'schemas' => [
							['id' => $schemaId, 'title' => 'Wetten en algemeen verbindende voorschriften'],
						],
					],
				],
				'configuration' => [
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
				],
			]);
	}

	/**
	 * Setup app manager and container for object service injection.
	 */
	private function setupAppManagerAndContainer(?object $objectService = null): void {
		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		if ($objectService !== null) {
			$this->container->method('get')
				->willReturn($objectService);
		}
	}
}
