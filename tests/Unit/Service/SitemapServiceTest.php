<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for SitemapService.
 */
class SitemapServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private SettingsService|MockObject $settingsService;
    private IURLGenerator|MockObject $urlGenerator;
    private SitemapService $service;

    protected function setUp(): void
    {
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->urlGenerator    = $this->createMock(IURLGenerator::class);

        $this->service = new SitemapService(
            $this->container,
            $this->appManager,
            $this->settingsService,
            $this->urlGenerator,
        );
    }

    // ──────────────────────────────────────────────────────────
    // INFO_CAT constant
    // ──────────────────────────────────────────────────────────

    public function testInfoCatHas17Entries(): void
    {
        $this->assertCount(17, SitemapService::INFO_CAT);
    }

    public function testInfoCatAllKeysPresent(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $key = sprintf('sitemapindex-diwoo-infocat%03d.xml', $i);
            $this->assertArrayHasKey($key, SitemapService::INFO_CAT, "Missing key: $key");
        }
    }

    public function testInfoCatFirstEntry(): void
    {
        $this->assertEquals(
            'Wetten en algemeen verbindende voorschriften',
            SitemapService::INFO_CAT['sitemapindex-diwoo-infocat001.xml']
        );
    }

    public function testInfoCatLastEntry(): void
    {
        $this->assertEquals(
            'Klachtoordelen',
            SitemapService::INFO_CAT['sitemapindex-diwoo-infocat017.xml']
        );
    }

    // ──────────────────────────────────────────────────────────
    // getObjectService (private, via reflection)
    // ──────────────────────────────────────────────────────────

    public function testGetObjectServiceAvailable(): void
    {
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

    public function testGetObjectServiceNotAvailable(): void
    {
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

    public function testGetFileServiceAvailable(): void
    {
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

    public function testGetFileServiceNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister FileService is not available.');

        $method = $this->getPrivateMethod('getFileService');
        $method->invoke($this->service);
    }

    // ──────────────────────────────────────────────────────────
    // isValidSitemapRequest (private, via reflection)
    // ──────────────────────────────────────────────────────────

    public function testIsValidSitemapRequestNoSettings(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $method = $this->getPrivateMethod('isValidSitemapRequest');
        $result = $method->invokeArgs($this->service, [
            'test-catalog',
            'sitemapindex-diwoo-infocat001.xml',
            $objectService,
        ]);

        $this->assertInstanceOf(XMLResponse::class, $result);
    }

    public function testIsValidSitemapRequestInvalidCategoryCode(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    ['title' => 'woo', 'id' => 'reg-1', 'schemas' => []],
                ],
            ]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $method = $this->getPrivateMethod('isValidSitemapRequest');
        $result = $method->invokeArgs($this->service, [
            'test-catalog',
            'invalid-category.xml',
            $objectService,
        ]);

        $this->assertInstanceOf(XMLResponse::class, $result);
    }

    public function testIsValidSitemapRequestValidRequest(): void
    {
        $schemaTitle = 'Wetten en algemeen verbindende voorschriften';

        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    [
                        'title'   => 'Woo',
                        'id'      => 'reg-woo',
                        'schemas' => [
                            ['id' => 'sch-1', 'title' => $schemaTitle],
                        ],
                    ],
                ],
                'configuration' => [
                    'catalog_register' => 'cat-reg',
                    'catalog_schema'   => 'cat-sch',
                ],
            ]);

        // Mock catalog object with getObject() and getSlug().
        $catalogObj = new class {
            public function getObject(): array
            {
                return ['schemas' => ['sch-1', 'sch-2']];
            }

            public function getSlug(): string
            {
                return 'my-catalog';
            }

            public function jsonSerialize(): array
            {
                return ['slug' => 'my-catalog'];
            }
        };

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [$catalogObj]]);

        $method = $this->getPrivateMethod('isValidSitemapRequest');

        $catalog    = null;
        $schemaId   = null;
        $registerId = null;

        $result = $method->invokeArgs($this->service, [
            'my-catalog',
            'sitemapindex-diwoo-infocat001.xml',
            $objectService,
            &$catalog,
            &$schemaId,
            &$registerId,
        ]);

        $this->assertTrue($result);
        $this->assertEquals('sch-1', $schemaId);
        $this->assertEquals('reg-woo', $registerId);
    }

    public function testIsValidSitemapRequestEmptyCatalog(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    [
                        'title'   => 'Woo',
                        'id'      => 'reg-woo',
                        'schemas' => [
                            ['id' => 'sch-1', 'title' => 'Wetten en algemeen verbindende voorschriften'],
                        ],
                    ],
                ],
                'configuration' => [
                    'catalog_register' => 'cat-reg',
                    'catalog_schema'   => 'cat-sch',
                ],
            ]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(['results' => []]);

        $method = $this->getPrivateMethod('isValidSitemapRequest');
        $result = $method->invokeArgs($this->service, [
            'nonexistent-catalog',
            'sitemapindex-diwoo-infocat001.xml',
            $objectService,
        ]);

        $this->assertInstanceOf(XMLResponse::class, $result);
    }

    public function testIsValidSitemapRequestSchemaNotInCatalog(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    [
                        'title'   => 'Woo',
                        'id'      => 'reg-woo',
                        'schemas' => [
                            ['id' => 'sch-99', 'title' => 'Wetten en algemeen verbindende voorschriften'],
                        ],
                    ],
                ],
                'configuration' => [
                    'catalog_register' => 'cat-reg',
                    'catalog_schema'   => 'cat-sch',
                ],
            ]);

        // Catalog that does not contain sch-99 in its schemas.
        $catalogObj = new class {
            public function getObject(): array
            {
                return ['schemas' => ['sch-1', 'sch-2']];
            }
        };

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [$catalogObj]]);

        $method = $this->getPrivateMethod('isValidSitemapRequest');
        $result = $method->invokeArgs($this->service, [
            'my-catalog',
            'sitemapindex-diwoo-infocat001.xml',
            $objectService,
        ]);

        $this->assertInstanceOf(XMLResponse::class, $result);
    }

    public function testIsValidSitemapRequestJaarplanTitleMapping(): void
    {
        // Category 012 = 'Jaarplannen en jaarverslagen'
        // The code maps 'Jaarplan of jaarverslag' to 'Jaarplannen en jaarverslagen'.
        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    [
                        'title'   => 'woo',
                        'id'      => 'reg-woo',
                        'schemas' => [
                            ['id' => 'sch-12', 'title' => 'Jaarplan of jaarverslag'],
                        ],
                    ],
                ],
                'configuration' => [
                    'catalog_register' => 'cat-reg',
                    'catalog_schema'   => 'cat-sch',
                ],
            ]);

        $catalogObj = new class {
            public function getObject(): array
            {
                return ['schemas' => ['sch-12']];
            }

            public function getSlug(): string
            {
                return 'test-slug';
            }
        };

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [$catalogObj]]);

        $method = $this->getPrivateMethod('isValidSitemapRequest');

        $catalog    = null;
        $schemaId   = null;
        $registerId = null;

        $result = $method->invokeArgs($this->service, [
            'test-slug',
            'sitemapindex-diwoo-infocat012.xml',
            $objectService,
            &$catalog,
            &$schemaId,
            &$registerId,
        ]);

        $this->assertTrue($result);
        $this->assertEquals('sch-12', $schemaId);
    }

    // ──────────────────────────────────────────────────────────
    // mapDiwooDocument (private, via reflection)
    // ──────────────────────────────────────────────────────────

    public function testMapDiwooDocument(): void
    {
        $publication = [
            'id'                => 'pub-1',
            'tooiCategorieNaam' => 'Woo verzoeken',
            'tooiCategorieUri'  => 'https://example.com/tooi/woo',
            '@self'             => [
                'created'      => '2024-01-15 10:00:00',
                'updated'      => '2024-06-20 14:30:00',
                'owner'        => 'admin',
                'organisation' => 'https://example.com/org/123',
                'published'    => '2024-03-01 08:00:00',
            ],
        ];

        $file = [
            'downloadUrl' => 'https://example.com/files/doc.pdf',
            'extension'   => 'pdf',
            'published'   => '2024-05-10 12:00:00',
            'owner'       => 'file-owner',
        ];

        $method = $this->getPrivateMethod('mapDiwooDocument');
        $result = $method->invoke($this->service, $publication, $file);

        $this->assertArrayHasKey('diwoo:Document', $result);

        $doc = $result['diwoo:Document']['diwoo:DiWoo'];

        $this->assertEquals('https://example.com/files/doc.pdf', $doc['loc']);
        $this->assertEquals(date('Y-m-d H:i:s', strtotime('2024-05-10 12:00:00')), $doc['lastmod']);
        $this->assertEquals('2024-01-15', $doc['diwoo:creatiedatum']);

        // Publisher.
        $this->assertEquals('https://example.com/org/123', $doc['diwoo:publisher']['@resource']);
        $this->assertEquals('file-owner', $doc['diwoo:publisher']['#text']);

        // Format.
        $this->assertStringContainsString('PDF', $doc['diwoo:format']['@resource']);
        $this->assertEquals('pdf', $doc['diwoo:format']['#text']);

        // Classification.
        $classification = $doc['diwoo:classificatiecollectie']['diwoo:informatiecategorieen']['diwoo:informatiecategorie'];
        $this->assertEquals('Woo verzoeken', $classification['#text']);
        $this->assertEquals('https://example.com/tooi/woo', $classification['@resource']);

        // Document handling.
        $handling = $doc['diwoo:documenthandelingen']['diwoo:documenthandeling'];
        $this->assertEquals('ontvangst', $handling['diwoo:soortHandeling']['#text']);
    }

    public function testMapDiwooDocumentFallbackValues(): void
    {
        $publication = [
            'id'    => 'pub-2',
            '@self' => [],
        ];

        $file = [
            'downloadUrl' => 'https://example.com/files/report.docx',
            'extension'   => 'docx',
        ];

        $method = $this->getPrivateMethod('mapDiwooDocument');
        $result = $method->invoke($this->service, $publication, $file);

        $doc = $result['diwoo:Document']['diwoo:DiWoo'];

        // Should use fallback values.
        $this->assertEquals('PLACEHOLDER_OWNER', $doc['diwoo:publisher']['#text']);
        $this->assertEquals('PLACEHOLDER_ORG_URI', $doc['diwoo:publisher']['@resource']);
        $this->assertEquals('PLACEHOLDER_CATEGORY', $doc['diwoo:classificatiecollectie']['diwoo:informatiecategorieen']['diwoo:informatiecategorie']['#text']);
        $this->assertEquals('PLACEHOLDER_CATEGORY_URI', $doc['diwoo:classificatiecollectie']['diwoo:informatiecategorieen']['diwoo:informatiecategorie']['@resource']);

        // Format should be uppercase extension in URI.
        $this->assertStringContainsString('DOCX', $doc['diwoo:format']['@resource']);
        $this->assertEquals('docx', $doc['diwoo:format']['#text']);
    }

    // ──────────────────────────────────────────────────────────
    // buildSitemapIndex
    // ──────────────────────────────────────────────────────────

    public function testBuildSitemapIndexInvalidCategory(): void
    {
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

    public function testBuildSitemapIndexEmptyResults(): void
    {
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

    public function testBuildSitemapIndexValidRequest(): void
    {
        $this->setupValidSitemapContext('reg-woo', 'sch-1');

        $pubObject = $this->createSerializableObject([
            '@self' => ['updated' => '2024-06-15 10:00:00'],
        ]);

        $catalogObj = $this->createCatalogObj(['sch-1']);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $callCount     = 0;
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
                    'total'   => 1,
                    'next'    => null,
                ];
                return null;
            });

        $this->setupAppManagerAndContainer($objectService);

        $this->urlGenerator->method('getBaseUrl')
            ->willReturn('https://example.com');

        $result = $this->service->buildSitemapIndex('my-catalog', 'sitemapindex-diwoo-infocat001.xml');

        $this->assertInstanceOf(XMLResponse::class, $result);
    }

    public function testBuildSitemapIndexWithPagination(): void
    {
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
                        'total'   => 2,
                        'next'    => 'page2-cursor',
                    ];
                }

                // Second page.
                return [
                    'results' => [$pubObject2],
                    'total'   => 2,
                    'next'    => null,
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

    public function testBuildSitemapValidRequest(): void
    {
        $this->setupValidSitemapContext('reg-woo', 'sch-1');

        $catalogObj = $this->createCatalogObj(['sch-1']);

        $pubObject = $this->createSerializableObject([
            'id'                => 'pub-1',
            'tooiCategorieNaam' => 'Test Category',
            'tooiCategorieUri'  => 'https://tooi/cat',
            '@self'             => [
                'created'      => '2024-01-01',
                'updated'      => '2024-06-01',
                'owner'        => 'admin',
                'organisation' => 'https://org',
            ],
        ]);

        $fileData = [
            'results' => [
                [
                    'downloadUrl' => 'https://example.com/file.pdf',
                    'extension'   => 'pdf',
                    'published'   => '2024-03-01',
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

    public function testBuildSitemapWithNoFiles(): void
    {
        $this->setupValidSitemapContext('reg-woo', 'sch-1');

        $catalogObj = $this->createCatalogObj(['sch-1']);

        $pubObject = $this->createSerializableObject([
            'id'    => 'pub-2',
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
    }

    public function testBuildSitemapWithFileMissingDownloadUrl(): void
    {
        $this->setupValidSitemapContext('reg-woo', 'sch-1');

        $catalogObj = $this->createCatalogObj(['sch-1']);

        $pubObject = $this->createSerializableObject([
            'id'    => 'pub-3',
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

    public function testBuildSitemapEmptyPublications(): void
    {
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
    // Helper methods
    // ──────────────────────────────────────────────────────────

    /**
     * Get a private method via reflection.
     */
    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }

    /**
     * Create a serializable object mock.
     */
    private function createSerializableObject(array $data): object
    {
        return new class ($data) {
            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function jsonSerialize(): array
            {
                return $this->data;
            }
        };
    }

    /**
     * Create a catalog object mock with getObject() and getSlug().
     */
    private function createCatalogObj(array $schemas): object
    {
        return new class ($schemas) {
            private array $schemas;

            public function __construct(array $schemas)
            {
                $this->schemas = $schemas;
            }

            public function getObject(): array
            {
                return ['schemas' => $this->schemas];
            }

            public function getSlug(): string
            {
                return 'my-catalog';
            }

            public function jsonSerialize(): array
            {
                return ['slug' => 'my-catalog', 'schemas' => $this->schemas];
            }
        };
    }

    /**
     * Setup settings service with valid Woo register and schema.
     */
    private function setupValidSitemapContext(string $registerId, string $schemaId): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'availableRegisters' => [
                    [
                        'title'   => 'Woo',
                        'id'      => $registerId,
                        'schemas' => [
                            ['id' => $schemaId, 'title' => 'Wetten en algemeen verbindende voorschriften'],
                        ],
                    ],
                ],
                'configuration' => [
                    'catalog_register' => 'cat-reg',
                    'catalog_schema'   => 'cat-sch',
                ],
            ]);
    }

    /**
     * Setup app manager and container for object service injection.
     */
    private function setupAppManagerAndContainer(?object $objectService = null): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        if ($objectService !== null) {
            $this->container->method('get')
                ->willReturn($objectService);
        }
    }
}
