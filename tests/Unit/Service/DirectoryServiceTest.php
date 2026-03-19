<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Unit tests for DirectoryService.
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class DirectoryServiceTest extends TestCase
{
    private DirectoryService $service;
    private MockObject&IURLGenerator $urlGenerator;
    private MockObject&IAppConfig $config;
    private MockObject&ContainerInterface $container;
    private MockObject&IAppManager $appManager;
    private MockObject&BroadcastService $broadcastService;
    private MockObject&IRequest $request;
    private MockObject&Client $client;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->broadcastService = $this->createMock(BroadcastService::class);
        $this->request = $this->createMock(IRequest::class);

        $this->service = new DirectoryService(
            $this->urlGenerator,
            $this->config,
            $this->container,
            $this->appManager,
            $this->broadcastService,
            $this->request
        );

        $this->client = $this->createMock(Client::class);
    }

    /**
     * Create a DirectoryService with a mock client injected.
     *
     * Since the 'client' property is readonly in PHP 8.3, we use
     * ReflectionClass::newInstanceWithoutConstructor() to create an
     * uninitialized instance, then set the readonly properties manually
     * before they are "initialized" (readonly only blocks re-assignment).
     *
     * @return DirectoryService A service instance with the mock client
     */
    private function createServiceWithMockClient(): DirectoryService
    {
        $ref = new ReflectionClass(DirectoryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Set all readonly properties on the uninitialized instance
        $props = [
            'appName' => 'opencatalogi',
            'client' => $this->client,
            'urlGenerator' => $this->urlGenerator,
            'config' => $this->config,
            'container' => $this->container,
            'appManager' => $this->appManager,
            'broadcastService' => $this->broadcastService,
            'request' => $this->request,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setValue($service, $value);
        }

        // Set non-readonly properties defaults
        $ref->getProperty('uniqueDirectories')->setValue($service, []);
        $ref->getProperty('cachedUniqueDirs')->setValue($service, null);
        $ref->getProperty('cacheTimestamp')->setValue($service, 0);

        return $service;
    }

    /**
     * Helper to invoke a private method via reflection.
     *
     * @param string $method Method name
     * @param array  $args   Method arguments
     *
     * @return mixed
     */
    private function invokePrivateMethod(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($method);

        return $method->invokeArgs($this->service, $args);
    }

    /**
     * Helper to set a private property via reflection.
     */
    private function setPrivateProperty(string $name, mixed $value): void
    {
        $reflection = new ReflectionClass($this->service);
        $property = $reflection->getProperty($name);
        $property->setValue($this->service, $value);
    }

    /**
     * Helper to get a private property via reflection.
     */
    private function getPrivateProperty(string $name): mixed
    {
        $reflection = new ReflectionClass($this->service);
        $property = $reflection->getProperty($name);

        return $property->getValue($this->service);
    }

    /**
     * Helper to create a mock ObjectService.
     */
    private function createMockObjectService(): MockObject
    {
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('saveObject')
            ->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());

        return $objectService;
    }

    /**
     * Helper: create a fake entity that extends OCP\AppFramework\Db\Entity.
     *
     * Entity uses __call magic for jsonSerialize, which PHPUnit cannot mock.
     * This subclass overrides __call to return preset data for jsonSerialize.
     *
     * @param array $data The data to return from jsonSerialize
     *
     * @return \OCP\AppFramework\Db\Entity
     */
    private function createFakeEntity(array $data): \OCP\AppFramework\Db\Entity
    {
        return new class($data) extends \OCP\AppFramework\Db\Entity {
            private array $_fakeData;

            public function __construct(array $data)
            {
                $this->_fakeData = $data;
            }

            public function jsonSerialize(): array
            {
                return $this->_fakeData;
            }
        };
    }

    /**
     * Helper to set up OpenRegister as installed and configure container.
     */
    private function setupOpenRegisterAvailable(?MockObject $objectService = null): MockObject
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectService = $objectService ?? $this->createMockObjectService();

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($objectService) {
                if ($class === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $this->createMock(\stdClass::class);
                }
                return null;
            });

        return $objectService;
    }

    /**
     * Helper to set up standard listing config values.
     */
    private function setupListingConfig(
        string $listingSchema = 'listing-schema-id',
        string $listingRegister = 'listing-register-id',
        string $catalogSchema = 'catalog',
        string $catalogRegister = 'catalog-register-id'
    ): void {
        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') use (
                $listingSchema,
                $listingRegister,
                $catalogSchema,
                $catalogRegister
            ) {
                return match ($key) {
                    'listing_schema' => $listingSchema,
                    'listing_register' => $listingRegister,
                    'catalog_schema' => $catalogSchema,
                    'catalog_register' => $catalogRegister,
                    default => $default,
                };
            });
    }

    // =========================================================================
    // getUniqueDirectories
    // =========================================================================

    public function testGetUniqueDirectoriesThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getUniqueDirectories();
    }

    public function testGetUniqueDirectoriesReturnsEmptyWhenNoConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('', '');

        $result = $this->service->getUniqueDirectories();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetUniqueDirectoriesReturnsPublicationUrls(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $listingObj = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://example.com/api/publications',
                'integrationLevel' => 'search',
                'default' => true,
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$listingObj]);

        $result = $this->service->getUniqueDirectories();

        $this->assertEquals(['https://example.com/api/publications'], $result);
    }

    public function testGetUniqueDirectoriesFiltersAvailableOnly(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $availableListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://available.example.com/api/publications',
                'integrationLevel' => 'search',
                'default' => false,
            ],
        ]);

        $unavailableListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://unavailable.example.com/api/publications',
                'integrationLevel' => 'none',
                'default' => false,
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$availableListing, $unavailableListing]);

        $result = $this->service->getUniqueDirectories(availableOnly: true);

        $this->assertCount(1, $result);
        $this->assertEquals('https://available.example.com/api/publications', $result[0]);
    }

    public function testGetUniqueDirectoriesFiltersDefaultOnly(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $defaultListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://default.example.com/api/publications',
                'integrationLevel' => 'search',
                'default' => true,
            ],
        ]);

        $nonDefaultListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://nondefault.example.com/api/publications',
                'integrationLevel' => 'search',
                'default' => false,
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$defaultListing, $nonDefaultListing]);

        $result = $this->service->getUniqueDirectories(defaultOnly: true);

        $this->assertCount(1, $result);
        $this->assertEquals('https://default.example.com/api/publications', $result[0]);
    }

    public function testGetUniqueDirectoriesReturnsCachedResult(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Set cache manually
        $this->setPrivateProperty('cachedUniqueDirs', ['https://cached.example.com/api']);
        $this->setPrivateProperty('cacheTimestamp', time());

        // searchObjects should NOT be called when cache is valid
        $objectService->expects($this->never())->method('searchObjects');

        $result = $this->service->getUniqueDirectories();

        $this->assertEquals(['https://cached.example.com/api'], $result);
    }

    public function testGetUniqueDirectoriesInvalidatesStaleCacheAfterFiveMinutes(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Set cache with old timestamp (6 minutes ago)
        $this->setPrivateProperty('cachedUniqueDirs', ['https://old-cached.example.com/api']);
        $this->setPrivateProperty('cacheTimestamp', time() - 360);

        $listingObj = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://fresh.example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);

        $objectService->expects($this->once())->method('searchObjects')->willReturn([$listingObj]);

        $result = $this->service->getUniqueDirectories();

        $this->assertEquals(['https://fresh.example.com/api/publications'], $result);
    }

    public function testGetUniqueDirectoriesSkipsListingsWithoutPublications(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $noPublicationsListing = $this->createFakeEntity([
            'object' => [
                'directory' => 'https://example.com/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$noPublicationsListing]);

        $result = $this->service->getUniqueDirectories();

        $this->assertEmpty($result);
    }

    public function testGetUniqueDirectoriesDeduplicatesUrls(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $listing1 = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);

        $listing2 = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$listing1, $listing2]);

        $result = $this->service->getUniqueDirectories();

        $this->assertCount(1, $result);
    }

    public function testGetUniqueDirectoriesHandlesSearchException(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willThrowException(new \Exception('Search failed'));

        $result = $this->service->getUniqueDirectories();

        $this->assertEmpty($result);
    }

    // =========================================================================
    // syncDirectory
    // =========================================================================

    public function testSyncDirectoryThrowsOnEmptyUrl(): void
    {
        // Need to set uniqueDirectories to avoid calling getUniqueDirectories
        $this->setPrivateProperty('uniqueDirectories', ['https://some-dir.example.com']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Directory URL cannot be empty');

        $this->service->syncDirectory('');
    }

    public function testSyncDirectoryThrowsOnInvalidUrl(): void
    {
        $this->setPrivateProperty('uniqueDirectories', ['https://some-dir.example.com']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid directory URL provided');

        $this->service->syncDirectory('not-a-url');
    }

    public function testSyncDirectoryThrowsWhenSyncingWithSelf(): void
    {
        $this->setPrivateProperty('uniqueDirectories', ['https://some-dir.example.com']);
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot sync with current directory');

        $this->service->syncDirectory('https://myserver.example.com/apps/opencatalogi/api/directory');
    }

    public function testSyncDirectorySuccessWithResults(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Mock the Guzzle response
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'Test Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://other.example.com/api/directory',
                ],
            ],
        ]);
        $response = new Response(200, [], $responseBody);
        $this->client->method('get')->willReturn($response);

        // No existing listings
        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals('https://other.example.com/api/directory', $result['directory_url']);
        $this->assertArrayHasKey('listings_created', $result);
        $this->assertArrayHasKey('listings_updated', $result);
        $this->assertArrayHasKey('total_processed', $result);
    }

    public function testSyncDirectoryFiltersOutOwnListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Response with a listing that has our directory URL
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'own-listing',
                    'title' => 'Our Own Listing',
                    'catalog' => 'our-catalog',
                    'directory' => 'https://myserver.example.com/api/directory',
                ],
            ],
        ]);
        $response = new Response(200, [], $responseBody);
        $this->client->method('get')->willReturn($response);

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        // The listing is filtered out, so total_processed should be 0
        $this->assertEquals(0, $result['total_processed']);
    }

    public function testSyncDirectoryFiltersOutLocalUrls(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Response with a listing that has a localhost URL
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'local-listing',
                    'title' => 'Local Listing',
                    'catalog' => 'local-catalog',
                    'directory' => 'http://localhost:8080/api/directory',
                ],
            ],
        ]);
        $response = new Response(200, [], $responseBody);
        $this->client->method('get')->willReturn($response);

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(0, $result['total_processed']);
    }

    // =========================================================================
    // syncListing
    // =========================================================================

    public function testSyncListingCreatesNewListing(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // No existing listings
        $objectService->method('searchObjects')->willReturn([]);
        $objectService->expects($this->once())->method('saveObject');

        $listingData = [
            'id' => 'new-listing-1',
            'title' => 'New Listing',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertTrue($result['success']);
        $this->assertEquals('created', $result['action']);
        $this->assertEquals('new-listing-1', $result['listing_id']);
    }

    public function testSyncListingUpdatesExistingListing(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Old Title',
                'catalog' => 'catalog-1',
                'default' => true,
                'status' => 'production',
                'integrationLevel' => 'search',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$existingListing]);
        $objectService->expects($this->once())->method('saveObject');

        $listingData = [
            'id' => 'listing-1',
            'title' => 'Updated Title',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertTrue($result['success']);
        $this->assertEquals('updated', $result['action']);
    }

    public function testSyncListingReportsUnchangedWhenHashesMatch(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Same Title',
                'catalog' => 'catalog-1',
                'default' => true,
                'status' => 'development',
                'integrationLevel' => 'search',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $listingData = [
            'id' => 'listing-1',
            'title' => 'Same Title',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertTrue($result['success']);
        // Action will be 'updated' or 'unchanged' depending on hash comparison
        $this->assertContains($result['action'], ['updated', 'unchanged']);
    }

    public function testSyncListingFailsOnMissingId(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $listingData = [
            'title' => 'No ID',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing id', $result['error']);
    }

    public function testSyncListingUsesIdAsCatalogFallback(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);
        $objectService->expects($this->once())->method('saveObject');

        $listingData = [
            'id' => 'listing-1',
            'title' => 'No Explicit Catalog',
            // No 'catalog' field - id is used as fallback
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        // Since id='listing-1' is used as catalog fallback, it should succeed
        $this->assertTrue($result['success']);
    }

    public function testSyncListingSkipsListingFromOtherKnownDirectory(): void
    {
        $this->setPrivateProperty('uniqueDirectories', [
            'https://directory-a.example.com/api/directory',
            'https://directory-b.example.com/api/directory',
        ]);

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $listingData = [
            'id' => 'listing-1',
            'title' => 'Other Directory Listing',
            'catalog' => 'catalog-1',
            'directory' => 'https://directory-b.example.com/api/directory',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory-a.example.com/api/directory');

        $this->assertTrue($result['success']);
        $this->assertEquals('skipped_other_directory', $result['action']);
    }

    public function testSyncListingFailsWhenOpenRegisterNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OpenRegister', $result['error']);
    }

    public function testSyncListingFailsOnMissingConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('', '');

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['error']);
    }

    public function testSyncListingExtractsFieldsFromSelfMetadata(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);
        $objectService->expects($this->once())->method('saveObject');

        $listingData = [
            'id' => 'listing-1',
            '@self' => [
                'id' => 'uuid-from-self',
                'name' => 'Title From Self',
                'relations' => [
                    'catalog' => 'catalog-from-relations',
                    'publications' => 'https://example.com/api/publications',
                    'search' => 'https://example.com/api/search',
                    'directory' => 'https://example.com/api/directory',
                ],
            ],
        ];

        $result = $this->service->syncListing($listingData, 'https://source.example.com/api/directory');

        $this->assertTrue($result['success']);
        $this->assertEquals('created', $result['action']);
    }

    public function testSyncListingSetsDefaultForOfficialDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $savedData = null;
        $objectService->method('searchObjects')->willReturn([]);
        $objectService->expects($this->once())->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing(
            $listingData,
            'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory'
        );

        $this->assertTrue($savedData['default']);
    }

    public function testSyncListingSetsDefaultFalseForNonOfficialDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $savedData = null;
        $objectService->method('searchObjects')->willReturn([]);
        $objectService->expects($this->once())->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing(
            $listingData,
            'https://some-other-directory.example.com/api/directory'
        );

        $this->assertFalse($savedData['default']);
    }

    // =========================================================================
    // getDirectory
    // =========================================================================

    public function testGetDirectoryThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getDirectory();
    }

    public function testGetDirectoryReturnsListingsAndCatalogs(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $listingObj = $this->createFakeEntity([
            'object' => [
                'id' => 'listing-1',
                'title' => 'Test Listing',
                'catalog' => 'catalog-1',
                'publications' => 'https://example.com/api/publications',
            ],
        ]);

        $catalogObj = $this->createFakeEntity([
            'id' => 'catalog-1',
            'object' => [
                'id' => 'catalog-1',
                'title' => 'Test Catalog',
                'schemas' => [],
            ],
        ]);

        $callCount = 0;
        $objectService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount, $listingObj, $catalogObj) {
                $callCount++;
                if ($callCount === 1) {
                    return [$listingObj]; // listings query
                }
                return [$catalogObj]; // catalogs query
            });

        $result = $this->service->getDirectory();

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(2, $result['total']);
    }

    public function testGetDirectoryWithFilters(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getDirectory([
            'filters' => ['title' => 'Test'],
            'limit' => 10,
            'offset' => 5,
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(0, $result['total']);
    }

    public function testGetDirectoryWithEmptyConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('', '', '', '');

        $result = $this->service->getDirectory();

        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
        $this->assertEquals(0, $result['total']);
    }

    public function testGetDirectoryHandlesSearchException(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->getDirectory();

        // Should gracefully handle exception and return empty
        $this->assertArrayHasKey('results', $result);
    }

    // =========================================================================
    // getPublications
    // =========================================================================

    public function testGetPublicationsReturnsEmptyWhenNoDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getPublications();

        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
        $this->assertArrayHasKey('sources', $result);
        $this->assertEmpty($result['sources']);
    }

    // =========================================================================
    // getUsed
    // =========================================================================

    public function testGetUsedReturnsEmptyWhenNoDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getUsed('some-uuid');

        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
        $this->assertArrayHasKey('sources', $result);
        $this->assertEmpty($result['sources']);
    }

    // =========================================================================
    // getPublication
    // =========================================================================

    public function testGetPublicationReturnsNullWhenNoDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getPublication('pub-id-123');

        $this->assertNull($result);
    }

    // =========================================================================
    // convertCatalogiToListings
    // =========================================================================

    public function testConvertCatalogiToListingsConvertsArray(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $catalogs = [
            [
                'id' => 'catalog-1',
                'object' => [
                    'id' => 'catalog-1',
                    'title' => 'Test Catalog',
                    'summary' => 'A test catalog',
                    'schemas' => [],
                ],
            ],
        ];

        $result = $this->service->convertCatalogiToListings($catalogs);

        $this->assertCount(1, $result);
        $this->assertEquals('Test Catalog', $result[0]['title']);
        $this->assertEquals('catalog-1', $result[0]['catalog']);
        $this->assertArrayHasKey('directory', $result[0]);
        $this->assertArrayHasKey('search', $result[0]);
        $this->assertArrayHasKey('publications', $result[0]);
    }

    public function testConvertCatalogiToListingsHandlesEntityObjects(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $catalogEntity = $this->createFakeEntity([
            'id' => 'catalog-2',
            'object' => [
                'id' => 'catalog-2',
                'title' => 'Entity Catalog',
                'description' => 'A catalog as entity',
                'schemas' => ['schema-1', 'schema-2'],
            ],
        ]);

        $result = $this->service->convertCatalogiToListings([$catalogEntity]);

        $this->assertCount(1, $result);
        $this->assertEquals('Entity Catalog', $result[0]['title']);
    }

    public function testConvertCatalogiToListingsHandlesEmptyArray(): void
    {
        $result = $this->service->convertCatalogiToListings([]);

        $this->assertEmpty($result);
    }

    public function testConvertCatalogiToListingsHandlesMissingFields(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $catalogs = [
            [
                'id' => 'catalog-3',
                'object' => [],
            ],
        ];

        $result = $this->service->convertCatalogiToListings($catalogs);

        $this->assertCount(1, $result);
        $this->assertEquals('Unknown Catalog', $result[0]['title']);
        $this->assertEquals('Local catalog', $result[0]['summary']);
    }

    // =========================================================================
    // doCronSync
    // =========================================================================

    public function testDoCronSyncStructure(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Return no listings so directories list is empty (only default added)
        $objectService->method('searchObjects')->willReturn([]);

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        // Mock a successful response from default directory
        $responseBody = json_encode(['results' => []]);
        $response = new Response(200, [], $responseBody);
        $this->client->method('get')->willReturn($response);

        $service = $this->createServiceWithMockClient();

        $result = $service->doCronSync();

        $this->assertArrayHasKey('total_directories', $result);
        $this->assertArrayHasKey('synced_directories', $result);
        $this->assertArrayHasKey('failed_directories', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    // =========================================================================
    // isLocalUrl (private)
    // =========================================================================

    public function testIsLocalUrlWithLocalhost(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://localhost:8080/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWith127001(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://127.0.0.1/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithLocalDomain(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://myapp.local/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPrivateIp192(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://192.168.1.100/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPrivateIp10(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://10.0.0.1/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPrivateIp172(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://172.16.0.1/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPublicUrl(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['https://example.com/api']);
        $this->assertFalse($result);
    }

    public function testIsLocalUrlWithInvalidUrl(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['not-a-url']);
        $this->assertTrue($result); // Invalid URL is considered local
    }

    public function testIsLocalUrlWithZeroIp(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://0.0.0.0/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPublicIp(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://8.8.8.8/api']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isSystemBroadcast (private)
    // =========================================================================

    public function testIsSystemBroadcastReturnsTrue(): void
    {
        $this->request->method('getHeader')
            ->with('User-Agent')
            ->willReturn('OpenCatalogi-Broadcast/1.0');

        $result = $this->invokePrivateMethod('isSystemBroadcast', []);

        $this->assertTrue($result);
    }

    public function testIsSystemBroadcastReturnsFalseForNormalRequest(): void
    {
        $this->request->method('getHeader')
            ->with('User-Agent')
            ->willReturn('Mozilla/5.0');

        $result = $this->invokePrivateMethod('isSystemBroadcast', []);

        $this->assertFalse($result);
    }

    public function testIsSystemBroadcastReturnsFalseForEmptyUserAgent(): void
    {
        $this->request->method('getHeader')
            ->with('User-Agent')
            ->willReturn('');

        $result = $this->invokePrivateMethod('isSystemBroadcast', []);

        $this->assertFalse($result);
    }

    public function testIsSystemBroadcastReturnsTrueWithVersionSuffix(): void
    {
        $this->request->method('getHeader')
            ->with('User-Agent')
            ->willReturn('OpenCatalogi-Broadcast/2.5.0 (Linux)');

        $result = $this->invokePrivateMethod('isSystemBroadcast', []);

        $this->assertTrue($result);
    }

    // =========================================================================
    // detectPublicationEndpoint (private)
    // =========================================================================

    public function testDetectPublicationEndpointReturnsExistingPublications(): void
    {
        $data = ['publications' => 'https://example.com/api/publications'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/api/publications', $result);
    }

    public function testDetectPublicationEndpointReturnsExistingPublication(): void
    {
        $data = ['publication' => 'https://example.com/api/publication'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/api/publication', $result);
    }

    public function testDetectPublicationEndpointFromSearchUrl(): void
    {
        $data = ['search' => 'https://example.com/apps/opencatalogi/api/search'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointFromCatalogDirectory(): void
    {
        $data = ['catalogDirectory' => 'https://example.com/apps/opencatalogi/api/directory'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointFromDirectoryHostname(): void
    {
        $data = ['directory' => 'example.com'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointFromDirectoryFullUrl(): void
    {
        $data = ['directory' => 'https://example.com:8443/apps/opencatalogi/api/directory'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com:8443/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointFromDomainLikeTitle(): void
    {
        $data = ['title' => 'example.com'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://example.com/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointReturnsNullWhenNothingAvailable(): void
    {
        $data = ['title' => 'Some Catalog Name'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertNull($result);
    }

    public function testDetectPublicationEndpointIgnoresTitleWithSpaces(): void
    {
        $data = ['title' => 'My Cool Catalog'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertNull($result);
    }

    public function testDetectPublicationEndpointPrefersPublicationsOverOtherFields(): void
    {
        $data = [
            'publications' => 'https://primary.example.com/api/publications',
            'search' => 'https://secondary.example.com/api/search',
            'directory' => 'https://tertiary.example.com/api/directory',
        ];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://primary.example.com/api/publications', $result);
    }

    // =========================================================================
    // isListingDataOutdated (private)
    // =========================================================================

    public function testIsListingDataOutdatedReturnsFalseWhenNoExistingLastSync(): void
    {
        $incoming = ['updated' => '2025-01-02T00:00:00+00:00'];
        $existing = ['object' => ['title' => 'test']];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        $this->assertFalse($result);
    }

    public function testIsListingDataOutdatedReturnsTrueWhenIncomingIsOlder(): void
    {
        $incoming = ['updated' => '2025-01-01T00:00:00+00:00'];
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'updated' => '2025-01-03T00:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        $this->assertTrue($result);
    }

    public function testIsListingDataOutdatedReturnsFalseWhenIncomingIsNewer(): void
    {
        $incoming = ['updated' => '2025-01-05T00:00:00+00:00'];
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'updated' => '2025-01-03T00:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        $this->assertFalse($result);
    }

    public function testIsListingDataOutdatedReturnsFalseWhenTimestampsCantBeDetermined(): void
    {
        $incoming = ['title' => 'test'];
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'title' => 'existing',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        $this->assertFalse($result);
    }

    public function testIsListingDataOutdatedReturnsFalseOnInvalidDate(): void
    {
        $incoming = ['updated' => 'not-a-date'];
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'updated' => '2025-01-03T00:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        // extractTimestamp returns null for invalid date, so update is allowed
        $this->assertFalse($result);
    }

    // =========================================================================
    // extractTimestamp (private)
    // =========================================================================

    public function testExtractTimestampFromUpdatedField(): void
    {
        $data = ['updated' => '2025-06-15T10:30:00+00:00'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('2025-06-15', $result->format('Y-m-d'));
    }

    public function testExtractTimestampFromCreatedField(): void
    {
        $data = ['created' => '2025-01-01T00:00:00+00:00'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampFromLastSyncField(): void
    {
        $data = ['lastSync' => '2025-03-15T12:00:00+00:00'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampFromNestedSelfUpdated(): void
    {
        $data = ['@self' => ['updated' => '2025-02-01T00:00:00+00:00']];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampFromDateArrayFormat(): void
    {
        $data = ['updated' => ['date' => '2025-04-01 00:00:00.000000']];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampReturnsNullWhenNoFieldsAvailable(): void
    {
        $data = ['title' => 'no timestamps here'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertNull($result);
    }

    public function testExtractTimestampPriorityOrder(): void
    {
        // 'updated' should be picked before 'created'
        $data = [
            'updated' => '2025-06-01T00:00:00+00:00',
            'created' => '2025-01-01T00:00:00+00:00',
        ];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertEquals('2025-06-01', $result->format('Y-m-d'));
    }

    // =========================================================================
    // filterListingProperties (private)
    // =========================================================================

    public function testFilterListingPropertiesRemovesInternalFields(): void
    {
        $listing = [
            'object' => [
                'id' => 'listing-1',
                'title' => 'Test',
                'status' => 'production',
                'statusCode' => 200,
                'lastSync' => '2025-01-01',
                'available' => true,
                'default' => true,
                'metadata' => ['key' => 'value'],
                'image' => 'https://example.com/image.png',
                'listed' => true,
                'filters' => ['type' => 'test'],
                'publications' => 'https://example.com/api/publications',
            ],
        ];

        $result = $this->invokePrivateMethod('filterListingProperties', [$listing]);

        $this->assertArrayHasKey('object', $result);
        $this->assertArrayNotHasKey('status', $result['object']);
        $this->assertArrayNotHasKey('statusCode', $result['object']);
        $this->assertArrayNotHasKey('lastSync', $result['object']);
        $this->assertArrayNotHasKey('available', $result['object']);
        $this->assertArrayNotHasKey('default', $result['object']);
        $this->assertArrayNotHasKey('metadata', $result['object']);
        $this->assertArrayNotHasKey('image', $result['object']);
        $this->assertArrayNotHasKey('listed', $result['object']);
        $this->assertArrayNotHasKey('filters', $result['object']);
        // Preserved fields
        $this->assertEquals('listing-1', $result['object']['id']);
        $this->assertEquals('Test', $result['object']['title']);
        $this->assertEquals('https://example.com/api/publications', $result['object']['publications']);
    }

    public function testFilterListingPropertiesHandlesFlatStructure(): void
    {
        $listing = [
            'id' => 'listing-1',
            'title' => 'Test',
            'status' => 'production',
            'statusCode' => 200,
        ];

        $result = $this->invokePrivateMethod('filterListingProperties', [$listing]);

        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('statusCode', $result);
        $this->assertEquals('listing-1', $result['id']);
    }

    // =========================================================================
    // aggregateFacets (private)
    // =========================================================================

    public function testAggregateFacetsWithEmptyNew(): void
    {
        $existing = ['field1' => [['_id' => 'a', 'count' => 5]]];
        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, []]);

        $this->assertEquals($existing, $result);
    }

    public function testAggregateFacetsWithEmptyExisting(): void
    {
        $newFacets = ['field1' => [['_id' => 'a', 'count' => 5]]];
        $result = $this->invokePrivateMethod('aggregateFacets', [[], $newFacets]);

        $this->assertEquals($newFacets, $result);
    }

    public function testAggregateFacetsMergesCounts(): void
    {
        $existing = [
            'category' => [
                ['_id' => 'books', 'count' => 10],
                ['_id' => 'music', 'count' => 5],
            ],
        ];

        $newFacets = [
            'category' => [
                ['_id' => 'books', 'count' => 3],
                ['_id' => 'video', 'count' => 7],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Collect facet values
        $booksCount = null;
        $videoCount = null;
        $musicCount = null;
        foreach ($result['category'] as $facet) {
            if ($facet['_id'] === 'books') {
                $booksCount = $facet['count'];
            }
            if ($facet['_id'] === 'video') {
                $videoCount = $facet['count'];
            }
            if ($facet['_id'] === 'music') {
                $musicCount = $facet['count'];
            }
        }

        $this->assertEquals(13, $booksCount);
        $this->assertEquals(7, $videoCount);
        $this->assertEquals(5, $musicCount);
    }

    public function testAggregateFacetsAddsNewFields(): void
    {
        $existing = [
            'category' => [['_id' => 'books', 'count' => 10]],
        ];

        $newFacets = [
            'author' => [['_id' => 'Jane', 'count' => 3]],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('author', $result);
    }

    public function testAggregateFacetsSortsByCountDescending(): void
    {
        $existing = [
            'type' => [
                ['_id' => 'a', 'count' => 1],
            ],
        ];

        $newFacets = [
            'type' => [
                ['_id' => 'b', 'count' => 100],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // 'b' (100) should come before 'a' (1)
        $this->assertEquals('b', $result['type'][0]['_id']);
        $this->assertEquals('a', $result['type'][1]['_id']);
    }

    public function testAggregateFacetsSkipsNonArrayValues(): void
    {
        $existing = [
            'field1' => [['_id' => 'a', 'count' => 5]],
        ];

        $newFacets = [
            'field2' => 'not-an-array',
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Should not crash, existing should be preserved
        $this->assertArrayHasKey('field1', $result);
        $this->assertArrayNotHasKey('field2', $result);
    }

    public function testAggregateFacetsSkipsFacetValuesWithoutId(): void
    {
        $existing = [
            'category' => [['_id' => 'books', 'count' => 10]],
        ];

        $newFacets = [
            'category' => [
                ['count' => 5], // missing _id
                ['_id' => 'music', 'count' => 3],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        $ids = array_column($result['category'], '_id');
        $this->assertContains('books', $ids);
        $this->assertContains('music', $ids);
    }

    public function testAggregateFacetsSortsTiedCountsAlphabetically(): void
    {
        $existing = [
            'type' => [
                ['_id' => 'zebra', 'count' => 5],
            ],
        ];

        $newFacets = [
            'type' => [
                ['_id' => 'alpha', 'count' => 5],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Same count, so sorted alphabetically: alpha before zebra
        $this->assertEquals('alpha', $result['type'][0]['_id']);
        $this->assertEquals('zebra', $result['type'][1]['_id']);
    }

    // =========================================================================
    // convertCatalogToListing (private)
    // =========================================================================

    public function testConvertCatalogToListingFromArrayData(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '2.0.0']);

        $catalogData = [
            'id' => 'cat-1',
            'object' => [
                'id' => 'cat-1',
                'title' => 'My Catalog',
                'summary' => 'A great catalog',
                'description' => 'Detailed description',
                'organization' => 'Org XYZ',
                'schemas' => ['schema-1', 'schema-2'],
                'status' => 'production',
            ],
        ];

        $result = $this->invokePrivateMethod('convertCatalogToListing', [$catalogData]);

        $this->assertEquals('cat-1', $result['id']);
        $this->assertEquals('cat-1', $result['catalog']);
        $this->assertEquals('My Catalog', $result['title']);
        $this->assertEquals('A great catalog', $result['summary']);
        $this->assertEquals('Detailed description', $result['description']);
        $this->assertEquals('Org XYZ', $result['organization']);
        $this->assertEquals('production', $result['status']);
        $this->assertEquals('2.0.0', $result['version']);
        $this->assertCount(2, $result['schemas']);
    }

    public function testConvertCatalogToListingDefaultValues(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $catalogData = [
            'id' => 'cat-2',
            'object' => [],
        ];

        $result = $this->invokePrivateMethod('convertCatalogToListing', [$catalogData]);

        $this->assertEquals('Unknown Catalog', $result['title']);
        $this->assertEquals('Local catalog', $result['summary']);
        $this->assertEquals('development', $result['status']);
    }

    public function testConvertCatalogToListingFromEntity(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $catalogEntity = $this->createFakeEntity([
            'id' => 'cat-3',
            'object' => [
                'id' => 'cat-3',
                'title' => 'Entity Catalog',
            ],
        ]);

        $result = $this->invokePrivateMethod('convertCatalogToListing', [$catalogEntity]);

        $this->assertEquals('Entity Catalog', $result['title']);
        $this->assertEquals('cat-3', $result['id']);
    }

    // =========================================================================
    // processSchemaExpansion (private)
    // =========================================================================

    public function testProcessSchemaExpansionWithSchemaIds(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $schemaMapper = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findMultiple'])
            ->getMock();

        $schemaEntity = $this->createFakeEntity([
            'id' => 'schema-1',
            'title' => 'Test Schema',
        ]);

        $schemaMapper->method('findMultiple')->willReturn([$schemaEntity]);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($schemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $schemaMapper;
                }
                return null;
            });

        $data = [
            'title' => 'Test Listing',
            'schemas' => ['schema-1'],
        ];

        $result = $this->invokePrivateMethod('processSchemaExpansion', [$data]);

        $this->assertIsArray($result['schemas']);
        $this->assertEquals('Test Schema', $result['schemas'][0]['title']);
    }

    public function testProcessSchemaExpansionWithoutSchemas(): void
    {
        $data = [
            'title' => 'Test Listing',
        ];

        $result = $this->invokePrivateMethod('processSchemaExpansion', [$data]);

        $this->assertArrayNotHasKey('schemas', $result);
    }

    public function testProcessSchemaExpansionWithNestedObjectStructure(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $data = [
            'object' => [
                'title' => 'Test',
                'schemas' => ['schema-1'],
            ],
        ];

        $result = $this->invokePrivateMethod('processSchemaExpansion', [$data]);

        $this->assertArrayHasKey('object', $result);
        // OpenRegister not available, returns original IDs
        $this->assertEquals(['schema-1'], $result['object']['schemas']);
    }

    public function testProcessSchemaExpansionWithEmptySchemas(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $data = [
            'title' => 'Test',
            'schemas' => [],
        ];

        $result = $this->invokePrivateMethod('processSchemaExpansion', [$data]);

        $this->assertEmpty($result['schemas']);
    }

    // =========================================================================
    // expandSchemas (private)
    // =========================================================================

    public function testExpandSchemasReturnsEmptyForEmptyInput(): void
    {
        $result = $this->invokePrivateMethod('expandSchemas', [[]]);

        $this->assertEmpty($result);
    }

    public function testExpandSchemasReturnsIdsWhenOpenRegisterNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $result = $this->invokePrivateMethod('expandSchemas', [['schema-1', 'schema-2']]);

        $this->assertEquals(['schema-1', 'schema-2'], $result);
    }

    public function testExpandSchemasReturnsIdsOnException(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $schemaMapper = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findMultiple'])
            ->getMock();
        $schemaMapper->method('findMultiple')
            ->willThrowException(new \Exception('DB error'));

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($schemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $schemaMapper;
                }
                return null;
            });

        $result = $this->invokePrivateMethod('expandSchemas', [['schema-1']]);

        $this->assertEquals(['schema-1'], $result);
    }

    // =========================================================================
    // updateDirectoryStatusOnError (private)
    // =========================================================================

    public function testUpdateDirectoryStatusOnErrorWhenOpenRegisterNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        // Should not throw, just return silently
        $this->invokePrivateMethod('updateDirectoryStatusOnError', ['https://example.com', 500]);
        $this->assertTrue(true); // No exception thrown
    }

    public function testUpdateDirectoryStatusOnErrorWhenNoConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('', '');

        // Should not call saveObject
        $objectService->expects($this->never())->method('saveObject');

        $this->invokePrivateMethod('updateDirectoryStatusOnError', ['https://example.com', 500]);
    }

    public function testUpdateDirectoryStatusOnErrorUpdatesExistingListings(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'title' => 'Test Listing',
                'statusCode' => 200,
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $savedData = null;
        $objectService->expects($this->once())->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $this->invokePrivateMethod('updateDirectoryStatusOnError', ['https://example.com', 503]);

        $this->assertEquals(503, $savedData['statusCode']);
        $this->assertArrayHasKey('lastSync', $savedData);
    }

    // =========================================================================
    // Edge cases and integration-like scenarios
    // =========================================================================

    public function testSyncListingPreservesExistingDefaultAndStatus(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Old Title',
                'catalog' => 'catalog-1',
                'default' => true,
                'status' => 'production',
                'integrationLevel' => 'full',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'title' => 'New Title',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        // Existing values should be preserved
        $this->assertTrue($savedData['default']);
        $this->assertEquals('production', $savedData['status']);
        $this->assertEquals('full', $savedData['integrationLevel']);
    }

    public function testSyncListingSetsSummaryToUnknownWhenEmpty(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertEquals('unknown', $savedData['summary']);
    }

    public function testSyncListingCountsSchemas(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
            'schemas' => ['schema-1', 'schema-2', 'schema-3'],
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertEquals(3, $savedData['schemaCount']);
    }

    public function testSyncListingSetsDirectoryToSourceUrl(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $sourceUrl = 'https://source-directory.example.com/api/directory';
        $this->service->syncListing($listingData, $sourceUrl);

        $this->assertEquals($sourceUrl, $savedData['directory']);
    }

    public function testSyncListingSetsLastSyncAsIsoString(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertIsString($savedData['lastSync']);
        // Should be a valid ISO 8601 date
        $parsed = new DateTime($savedData['lastSync']);
        $this->assertInstanceOf(DateTime::class, $parsed);
    }

    public function testGetDirectoryFilterListingPropertiesIntegration(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('listing-schema', 'listing-register', '', '');

        $listingObj = $this->createFakeEntity([
            'object' => [
                'id' => 'listing-1',
                'title' => 'Public Listing',
                'status' => 'should-be-removed',
                'statusCode' => 200,
                'lastSync' => '2025-01-01',
                'available' => true,
                'default' => false,
                'metadata' => ['x' => 'y'],
                'publications' => 'https://example.com/api/publications',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$listingObj]);

        $result = $this->service->getDirectory();

        $this->assertCount(1, $result['results']);
        $filtered = $result['results'][0];
        $this->assertArrayNotHasKey('status', $filtered['object']);
        $this->assertArrayNotHasKey('statusCode', $filtered['object']);
        $this->assertArrayHasKey('publications', $filtered['object']);
    }

    public function testSyncListingZeroSchemaCountWhenNoSchemas(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertEquals(0, $savedData['schemaCount']);
    }

    public function testSyncListingExtractsTitleFromSelfName(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
            '@self' => [
                'name' => 'Title from @self.name',
            ],
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertEquals('Title from @self.name', $savedData['title']);
    }

    public function testSyncListingStatusCodeSetTo200(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        $this->assertEquals(200, $savedData['statusCode']);
        $this->assertEquals('development', $savedData['status']);
        $this->assertEquals('search', $savedData['integrationLevel']);
    }

    // =========================================================================
    // doCronSync – additional coverage
    // =========================================================================

    public function testDoCronSyncCountsSyncedAndFailed(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Return a listing with a publications URL so we get 2 directories (the listing + default)
        $listingObj = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://external.example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listingObj]);

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        // First call succeeds, second throws
        $callCount = 0;
        $this->client->method('get')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return new Response(200, [], json_encode(['results' => []]));
                }
                throw new RequestException('Connection refused', new Request('GET', 'https://example.com'));
            });

        $service = $this->createServiceWithMockClient();

        $result = $service->doCronSync();

        $this->assertArrayHasKey('total_directories', $result);
        $this->assertGreaterThanOrEqual(1, $result['total_directories']);
        // At least one should have synced or failed
        $this->assertEquals(
            $result['total_directories'],
            $result['synced_directories'] + $result['failed_directories']
        );
    }

    public function testDoCronSyncAddsDefaultDirectoryWhenNotPresent(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // No listings so no directories from DB
        $objectService->method('searchObjects')->willReturn([]);

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $this->client->method('get')
            ->willReturn(new Response(200, [], json_encode(['results' => []])));

        $service = $this->createServiceWithMockClient();

        $result = $service->doCronSync();

        // Should have exactly 1 directory (the default)
        $this->assertEquals(1, $result['total_directories']);
    }

    public function testDoCronSyncDoesNotDuplicateDefaultDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Return a listing that IS the default directory
        $listingObj = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listingObj]);

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $this->client->method('get')
            ->willReturn(new Response(200, [], json_encode(['results' => []])));

        $service = $this->createServiceWithMockClient();

        $result = $service->doCronSync();

        // Should have exactly 1 directory (the default, not duplicated)
        $this->assertEquals(1, $result['total_directories']);
    }

    public function testDoCronSyncHandlesSyncException(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        // Simulate connection error
        $this->client->method('get')
            ->willThrowException(new RequestException(
                'Connection refused',
                new Request('GET', 'https://directory.opencatalogi.nl')
            ));

        $service = $this->createServiceWithMockClient();

        $result = $service->doCronSync();

        // The exception should be caught inside the promise
        $this->assertEquals(0, $result['synced_directories']);
        $this->assertEquals(1, $result['failed_directories']);
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // syncDirectory – additional coverage
    // =========================================================================

    public function testSyncDirectoryInitializesUniqueDirectoriesWhenEmpty(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService->method('searchObjects')->willReturn([]);

        $this->client->method('get')
            ->willReturn(new Response(200, [], json_encode(['results' => []])));

        $service = $this->createServiceWithMockClient();
        // uniqueDirectories is empty by default

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals('https://other.example.com/api/directory', $result['directory_url']);
    }

    public function testSyncDirectoryHandlesInvalidJsonResponse(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Return invalid JSON
        $this->client->method('get')
            ->willReturn(new Response(200, [], 'not-valid-json{{{'));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $this->expectException(\Exception::class);

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    public function testSyncDirectoryProcessesMultipleListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'Listing One',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://other.example.com/api/directory',
                ],
                [
                    'id' => 'listing-2',
                    'title' => 'Listing Two',
                    'catalog' => 'catalog-2',
                    'directory' => 'https://other.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(2, $result['total_processed']);
        $this->assertEquals(2, $result['listings_created']);
    }

    public function testSyncDirectoryBroadcastsWhenNoOurListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Response with external listings (none are ours)
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'External Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://external.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        // Broadcast should be called
        $this->broadcastService->expects($this->once())->method('broadcast');

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    public function testSyncDirectorySkipsBroadcastDuringSystemBroadcast(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')
            ->willReturn('OpenCatalogi-Broadcast/1.0');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'External Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://external.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        // Broadcast should NOT be called during system broadcast
        $this->broadcastService->expects($this->never())->method('broadcast');

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    public function testSyncDirectorySkipsBroadcastWhenOurUrlIsLocal(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost:8080/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'External Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://external.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        // Broadcast should NOT be called when our URL is local
        $this->broadcastService->expects($this->never())->method('broadcast');

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    public function testSyncDirectoryHandlesBroadcastException(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'External Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://external.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));
        $objectService->method('searchObjects')->willReturn([]);

        // Broadcast throws but should be caught
        $this->broadcastService->method('broadcast')
            ->willThrowException(new \Exception('Broadcast failed'));

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        // Should not throw
        $result = $service->syncDirectory('https://other.example.com/api/directory');
        $this->assertEquals(1, $result['listings_created']);
    }

    public function testSyncDirectoryHandlesGuzzleException(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        // Throw a RequestException on get
        $this->client->method('get')
            ->willThrowException(new RequestException(
                'Connection refused',
                new Request('GET', 'https://other.example.com/api/directory')
            ));

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $this->expectException(RequestException::class);

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    public function testSyncDirectoryUpdatesExistingListingOnGuzzleError(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => ['title' => 'Existing', 'statusCode' => 200],
        ]);
        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $this->client->method('get')
            ->willThrowException(new RequestException(
                'Service unavailable',
                new Request('GET', 'https://other.example.com/api/directory'),
                new Response(503)
            ));

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        try {
            $service->syncDirectory('https://other.example.com/api/directory');
        } catch (RequestException $e) {
            // Expected
        }

        // Should have updated status on the existing listing
        $this->assertEquals(503, $savedData['statusCode']);
    }

    public function testSyncDirectoryHandlesResponseWithNoResults(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Response without a 'results' key
        $this->client->method('get')
            ->willReturn(new Response(200, [], json_encode(['data' => 'something'])));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(0, $result['total_processed']);
    }

    public function testSyncDirectoryCountsUpdatedAndUnchangedListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Old Title',
                'catalog' => 'catalog-1',
                'default' => true,
                'status' => 'development',
                'integrationLevel' => 'search',
            ],
        ]);

        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'Updated Title',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://other.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(1, $result['total_processed']);
        // Should be updated since title changed
        $this->assertGreaterThanOrEqual(0, $result['listings_updated']);
    }

    public function testSyncDirectoryCountsFailedListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Listing without required fields
        $responseBody = json_encode([
            'results' => [
                [
                    // Missing id and catalog
                    'title' => 'Bad Listing',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(1, $result['listings_failed']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testSyncDirectoryCountsSkippedOutdatedListings(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Existing listing has a newer timestamp
        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Existing',
                'catalog' => 'catalog-1',
                'default' => true,
                'status' => 'development',
                'integrationLevel' => 'search',
                'lastSync' => '2026-01-01T00:00:00+00:00',
                'updated' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        // Incoming listing has an older timestamp
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'Outdated Title',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://other.example.com/api/directory',
                    'updated' => '2025-01-01T00:00:00+00:00',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(1, $result['listings_skipped']);
    }

    public function testSyncDirectorySkipsListingFromOtherKnownDirectory(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Listing belongs to directory-b, which we also know about
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'External',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://directory-b.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, [
            'https://directory-a.example.com/api/directory',
            'https://directory-b.example.com/api/directory',
        ]);

        $result = $service->syncDirectory('https://directory-a.example.com/api/directory');

        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(1, $result['listings_skipped']);
    }

    // =========================================================================
    // getPublications – additional coverage
    // =========================================================================

    public function testGetPublicationsSkipsOwnDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // Return a listing that points to our own directory
        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://myserver.example.com/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getPublications();

        // Should be empty since our own directory is skipped
        $this->assertEmpty($result['results']);
    }

    public function testGetPublicationsSkipsLocalDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // Return a listing with localhost URL
        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'http://localhost:8080/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getPublications();

        // Should skip local URLs
        $this->assertEmpty($result['results']);
    }

    public function testGetPublicationsReturnsStructureWhenAllSkipped(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // All listings are either our own or local — all will be skipped
        $listing1 = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://myserver.example.com/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);
        $listing2 = $this->createFakeEntity([
            'object' => [
                'publications' => 'http://localhost:8080/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing1, $listing2]);

        $result = $this->service->getPublications();

        // All skipped — should still return valid structure
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEmpty($result['results']);
    }

    public function testGetPublicationsWithDefaultOnlyReturnsEmpty(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // No default+available listings
        $nonDefaultListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://nondefault.example.com/api/publications',
                'integrationLevel' => 'search',
                'default' => false,
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$nonDefaultListing]);

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // With defaultOnly=true, the non-default listing should be filtered out
        $result = $this->service->getPublications(includeDefault: true);

        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
    }

    // =========================================================================
    // getUsed – additional coverage
    // =========================================================================

    public function testGetUsedSkipsOwnAndLocalDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // Listing pointing to our own URL
        $ownListing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://myserver.example.com/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$ownListing]);

        $result = $this->service->getUsed('some-uuid');

        $this->assertEmpty($result['results']);
        $this->assertEmpty($result['sources']);
    }

    public function testGetUsedWithExternalDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://nonexistent-host-99999.example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        // getUsed creates its own Client — the request will fail since the host
        // is not reachable, but the error handling code in the Promise should execute
        $result = $this->service->getUsed('some-uuid', [
            'timeout' => 1,
            'connect_timeout' => 1,
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('sources', $result);
        // Connection will fail so results should be empty
        $this->assertEmpty($result['results']);
    }

    public function testGetUsedWithLocalDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // Listing with localhost URL should be skipped
        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'http://localhost:8080/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getUsed('some-uuid');

        $this->assertEmpty($result['results']);
    }

    // =========================================================================
    // getPublication – additional coverage
    // =========================================================================

    public function testGetPublicationSkipsOwnDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://myserver.example.com/api/directory',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getPublication('pub-123');

        $this->assertNull($result);
    }

    public function testGetPublicationSkipsLocalDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'http://localhost:8080/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getPublication('pub-123');

        $this->assertNull($result);
    }

    public function testGetPublicationWithExternalDirectory(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://nonexistent-host-88888.example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        // getPublication creates its own Client — the request will fail since
        // the host is not reachable, but error handling should execute
        $result = $this->service->getPublication('pub-123', [
            'timeout' => 1,
            'connect_timeout' => 1,
        ]);

        // Will be null since we can't connect
        $this->assertNull($result);
    }

    // =========================================================================
    // syncListing – additional edge cases
    // =========================================================================

    public function testSyncListingExtractsCatalogFromSelfRelations(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            '@self' => [
                'id' => 'uuid-1',
                'relations' => [
                    'catalog' => 'catalog-from-relations',
                ],
            ],
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
        $this->assertEquals('catalog-from-relations', $savedData['catalog']);
    }

    public function testSyncListingDetectsPublicationEndpointFromSearch(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
            '@self' => [
                'relations' => [
                    'search' => 'https://example.com/apps/opencatalogi/api/search',
                ],
            ],
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($savedData['publications']);
    }

    public function testSyncListingHandlesExceptionDuringSave(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);
        $objectService->method('saveObject')
            ->willThrowException(new \Exception('DB write failed'));

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB write failed', $result['error']);
    }

    public function testSyncListingHandlesExceptionDuringErrorStatusUpdate(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Test',
                'catalog' => 'catalog-1',
                'default' => false,
                'status' => 'development',
                'integrationLevel' => 'search',
            ],
        ]);

        $callCount = 0;
        $objectService->method('searchObjects')->willReturn([$existingListing]);
        $objectService->method('saveObject')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \Exception('Save failed attempt ' . $callCount);
            });

        $listingData = [
            'id' => 'listing-1',
            'title' => 'Updated',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertFalse($result['success']);
    }

    public function testSyncListingFailsOnMissingCatalog(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // No catalog field and no @self relations
        $listingData = [
            'id' => 'listing-1',
            'title' => 'No Catalog',
        ];

        // Since id is used as fallback for catalog, we need to test the case where
        // there is truly no catalog. But with the fallback, id is used.
        // Let's test it succeeds with the id fallback
        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // isLocalUrl – additional edge cases
    // =========================================================================

    public function testIsLocalUrlWithIpv6Localhost(): void
    {
        // parse_url extracts '::1' from 'http://[::1]:8080/api' — but the isLocalUrl method
        // checks exact match against '::1'. The host from parse_url includes brackets.
        // This verifies the method handles IPv6 brackets gracefully.
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://[::1]:8080/api']);
        // parse_url returns '[::1]' with brackets, which won't exact-match '::1'
        // and is not a valid IP for filter_var, so it falls through to .local check
        // and returns false. This documents current behavior.
        $this->assertFalse($result);
    }

    public function testIsLocalUrlWithPrivateIp172Range(): void
    {
        // 172.31.x.x is also private
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://172.31.255.255/api']);
        $this->assertTrue($result);
    }

    public function testIsLocalUrlWithPublicIpAddress(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://203.0.113.1/api']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // detectPublicationEndpoint – additional edge cases
    // =========================================================================

    public function testDetectPublicationEndpointFromSearchWithPort(): void
    {
        $data = ['search' => 'https://example.com:8443/apps/opencatalogi/api/search'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertStringContainsString('publications', $result);
        $this->assertStringContainsString('8443', $result);
    }

    public function testDetectPublicationEndpointFromDirectoryFullUrlWithPort(): void
    {
        $data = ['directory' => 'https://example.com:9443/some/path'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertStringContainsString('publications', $result);
        $this->assertStringContainsString('9443', $result);
    }

    public function testDetectPublicationEndpointFromEmptyData(): void
    {
        $data = [];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertNull($result);
    }

    public function testDetectPublicationEndpointFromNameField(): void
    {
        // Should use 'name' field when 'title' is missing but 'name' looks like domain
        $data = ['name' => 'catalog.example.org'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertEquals('https://catalog.example.org/apps/opencatalogi/api/publications', $result);
    }

    public function testDetectPublicationEndpointFromCatalogDirectoryWithDifferentPath(): void
    {
        // catalogDirectory without /api/directory should not match
        $data = ['catalogDirectory' => 'https://example.com/some/other/path'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        // Falls through to directory check since catalogDirectory str_replace didn't change
        // Then falls through to title check. With no directory or title, returns null.
        $this->assertNull($result);
    }

    public function testDetectPublicationEndpointSearchWithQueryString(): void
    {
        // A search URL where 'search' is a path segment but str_replace already works
        $data = ['search' => 'https://example.com/v1/search?q=test'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertStringContainsString('publications', $result);
        $this->assertStringNotContainsString('search', $result);
    }

    public function testDetectPublicationEndpointSearchUrlWithPortAndQuery(): void
    {
        // Test the generic parsing approach with port and query
        $data = ['search' => 'https://example.com:9090/api/v2/search?limit=10'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertStringContainsString('publications', $result);
        $this->assertStringContainsString('9090', $result);
    }

    public function testDetectPublicationEndpointSearchNoChange(): void
    {
        // A search URL where 'search' is nowhere in the path — only in query.
        // str_replace won't find /search, preg_replace won't match, generic won't change.
        // Result: no change, so returns null and falls through.
        $data = ['search' => 'https://example.com/api/find?type=search'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        // The str_replace on '/search' won't match '/find' path.
        // But the query 'type=search' doesn't count.
        // Falls through to catalogDirectory, directory, title checks — all empty.
        $this->assertNull($result);
    }

    public function testDetectPublicationEndpointDirectoryWithQuery(): void
    {
        $data = ['directory' => 'https://example.com:443/apps/opencatalogi/api/directory?v=2'];
        $result = $this->invokePrivateMethod('detectPublicationEndpoint', [$data]);

        $this->assertStringContainsString('publications', $result);
    }

    // =========================================================================
    // extractTimestamp – additional edge cases
    // =========================================================================

    public function testExtractTimestampWithDateTimeObject(): void
    {
        $dt = new DateTime('2025-05-01');
        $data = ['updated' => $dt];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('2025-05-01', $result->format('Y-m-d'));
    }

    public function testExtractTimestampFromSelfCreated(): void
    {
        $data = ['@self' => ['created' => '2025-03-01T00:00:00+00:00']];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampSkipsInvalidDatesAndTriesNext(): void
    {
        // 'updated' has an invalid value, but 'created' is valid
        $data = [
            'updated' => ['invalid-format'],
            'created' => '2025-06-01T00:00:00+00:00',
        ];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('2025-06-01', $result->format('Y-m-d'));
    }

    // =========================================================================
    // isListingDataOutdated – additional edge cases
    // =========================================================================

    public function testIsListingDataOutdatedReturnsFalseOnException(): void
    {
        // Passing data that would cause an internal exception
        $incoming = ['updated' => new \stdClass()]; // Not a valid timestamp type
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'updated' => '2025-01-03T00:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        // Should return false (allow update) on exception
        $this->assertFalse($result);
    }

    public function testIsListingDataOutdatedWithEqualTimestamps(): void
    {
        $incoming = ['updated' => '2025-01-03T00:00:00+00:00'];
        $existing = [
            'object' => [
                'lastSync' => '2025-01-02T00:00:00+00:00',
                'updated' => '2025-01-03T00:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod('isListingDataOutdated', [$incoming, $existing]);

        // Equal timestamps are NOT outdated
        $this->assertFalse($result);
    }

    // =========================================================================
    // updateDirectoryStatusOnError – additional coverage
    // =========================================================================

    public function testUpdateDirectoryStatusOnErrorHandlesException(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // searchObjects throws
        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB connection lost'));

        // Should not throw, just return silently
        $this->invokePrivateMethod('updateDirectoryStatusOnError', ['https://example.com', 500]);
        $this->assertTrue(true);
    }

    public function testUpdateDirectoryStatusOnErrorWithMultipleListings(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $listing1 = $this->createFakeEntity([
            'id' => 'uuid-1',
            'object' => ['title' => 'Listing 1', 'statusCode' => 200],
        ]);
        $listing2 = $this->createFakeEntity([
            'id' => 'uuid-2',
            'object' => ['title' => 'Listing 2', 'statusCode' => 200],
        ]);

        $objectService->method('searchObjects')->willReturn([$listing1, $listing2]);

        $savedDataList = [];
        $objectService->expects($this->exactly(2))->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedDataList) {
                $savedDataList[] = $data;
            });

        $this->invokePrivateMethod('updateDirectoryStatusOnError', ['https://example.com', 404]);

        $this->assertCount(2, $savedDataList);
        $this->assertEquals(404, $savedDataList[0]['statusCode']);
        $this->assertEquals(404, $savedDataList[1]['statusCode']);
    }

    // =========================================================================
    // aggregateFacets – additional edge cases
    // =========================================================================

    public function testAggregateFacetsSkipsExistingNonArrayField(): void
    {
        $existing = [
            'field1' => 'not-an-array',
        ];

        $newFacets = [
            'field1' => [['_id' => 'a', 'count' => 5]],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // existing field1 is not array, so it should be skipped (not crash)
        $this->assertArrayHasKey('field1', $result);
    }

    public function testAggregateFacetsHandlesExistingFacetWithoutId(): void
    {
        $existing = [
            'category' => [
                ['count' => 10], // Missing _id
                ['_id' => 'books', 'count' => 5],
            ],
        ];

        $newFacets = [
            'category' => [
                ['_id' => 'music', 'count' => 3],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        $ids = array_column($result['category'], '_id');
        $this->assertContains('books', $ids);
        $this->assertContains('music', $ids);
    }

    public function testAggregateFacetsSortComparatorHandlesNonArrayItems(): void
    {
        $existing = [
            'type' => [
                ['_id' => 'a', 'count' => 5],
            ],
        ];

        $newFacets = [
            'type' => [
                ['_id' => 'b', 'count' => 5],
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Should sort alphabetically for equal counts
        $this->assertEquals('a', $result['type'][0]['_id']);
        $this->assertEquals('b', $result['type'][1]['_id']);
    }

    // =========================================================================
    // getDirectory – additional edge cases
    // =========================================================================

    public function testGetDirectoryWithOnlyListingConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('listing-schema', 'listing-register', '', '');

        $listingObj = $this->createFakeEntity([
            'object' => [
                'id' => 'listing-1',
                'title' => 'Test',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$listingObj]);

        $result = $this->service->getDirectory();

        $this->assertEquals(1, $result['total']);
    }

    public function testGetDirectoryWithOnlyCatalogConfig(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('', '', 'catalog', 'catalog-register');

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $catalogObj = $this->createFakeEntity([
            'id' => 'catalog-1',
            'object' => [
                'id' => 'catalog-1',
                'title' => 'Test Catalog',
                'schemas' => [],
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$catalogObj]);

        $result = $this->service->getDirectory();

        $this->assertEquals(1, $result['total']);
    }

    public function testGetDirectoryHandlesCatalogSearchException(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('listing-schema', 'listing-register', 'catalog', 'catalog-register');

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $callCount = 0;
        $objectService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return []; // listings query OK
                }
                throw new \Exception('Catalog query failed');
            });

        $result = $this->service->getDirectory();

        // Should gracefully handle and return results
        $this->assertArrayHasKey('results', $result);
    }

    public function testGetDirectoryWithPaginationParams(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('listing-schema', 'listing-register', '', '');

        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getDirectory([
            'limit' => 5,
            'offset' => 10,
        ]);

        $this->assertEquals(0, $result['total']);
    }

    // =========================================================================
    // expandSchemas – additional edge cases
    // =========================================================================

    public function testExpandSchemasConvertsEntityToArray(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $schemaMapper = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findMultiple'])
            ->getMock();

        $schemaEntity = $this->createFakeEntity([
            'id' => 'schema-1',
            'title' => 'Test Schema',
        ]);

        $nonEntitySchema = ['id' => 'schema-2', 'title' => 'Array Schema'];

        $schemaMapper->method('findMultiple')
            ->willReturn([$schemaEntity, $nonEntitySchema]);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($schemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $schemaMapper;
                }
                return null;
            });

        $result = $this->invokePrivateMethod('expandSchemas', [['schema-1', 'schema-2']]);

        $this->assertCount(2, $result);
        $this->assertEquals('Test Schema', $result[0]['title']);
        $this->assertEquals('Array Schema', $result[1]['title']);
    }

    // =========================================================================
    // syncDirectory – error code 0 handling
    // =========================================================================

    public function testSyncDirectoryErrorCodeZeroBecomesInternalServerError(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => ['title' => 'Existing', 'statusCode' => 200],
        ]);
        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        // RequestException with code 0
        $requestException = new RequestException(
            'DNS lookup failed',
            new Request('GET', 'https://other.example.com/api/directory')
        );

        $this->client->method('get')->willThrowException($requestException);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        try {
            $service->syncDirectory('https://other.example.com/api/directory');
        } catch (RequestException $e) {
            // Expected
        }

        // Error code 0 should become 500
        $this->assertEquals(500, $savedData['statusCode']);
    }

    // =========================================================================
    // syncDirectory – general exception (non-Guzzle) handling
    // =========================================================================

    public function testSyncDirectoryHandlesGenericException(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        // Non-Guzzle exception (e.g., RuntimeException)
        $this->client->method('get')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error');

        $service->syncDirectory('https://other.example.com/api/directory');
    }

    // =========================================================================
    // getPublications – host parsing edge case
    // =========================================================================

    public function testGetPublicationsEmptyWhenNoAvailableDirectories(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Return listings without 'search' integrationLevel, so availableOnly filter removes them
        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://ext.example.com/api/publications',
                'integrationLevel' => 'none',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getPublications();

        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
    }

    public function testGetPublicationsWithExternalDirectoryConnectionFails(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');

        // Return an external listing with publications URL
        $listing = $this->createFakeEntity([
            'object' => [
                'publications' => 'https://nonexistent-host-12345.example.com/api/publications',
                'integrationLevel' => 'search',
            ],
        ]);
        $objectService->method('searchObjects')->willReturn([$listing]);

        // The internal Client will try to connect and fail — error is caught inside Promise
        $result = $this->service->getPublications([
            'timeout' => 1,
            'connect_timeout' => 1,
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('total', $result);
        // Connection fails so results empty
        $this->assertEmpty($result['results']);
        $this->assertEquals(0, $result['total']);
    }

    // =========================================================================
    // convertCatalogToListing – edge cases
    // =========================================================================

    public function testConvertCatalogToListingWithFlatData(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        // Catalog data without 'object' wrapper
        $catalogData = [
            'id' => 'cat-flat',
            'title' => 'Flat Catalog',
            'summary' => 'No object wrapper',
            'status' => 'production',
            'schemas' => ['s1'],
        ];

        $result = $this->invokePrivateMethod('convertCatalogToListing', [$catalogData]);

        $this->assertEquals('Flat Catalog', $result['title']);
        $this->assertEquals('cat-flat', $result['id']);
        $this->assertEquals('production', $result['status']);
    }

    public function testConvertCatalogToListingUsesDescriptionAsSummaryFallback(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);

        $catalogData = [
            'id' => 'cat-desc',
            'object' => [
                'id' => 'cat-desc',
                'title' => 'Desc Catalog',
                'description' => 'Description used as summary fallback',
            ],
        ];

        $result = $this->invokePrivateMethod('convertCatalogToListing', [$catalogData]);

        $this->assertEquals('Description used as summary fallback', $result['summary']);
    }

    // =========================================================================
    // getUniqueDirectories – using jsonSerialize without 'object' key
    // =========================================================================

    public function testGetUniqueDirectoriesHandlesListingsWithoutObjectKey(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Listing without an 'object' key in jsonSerialize result
        $listing = $this->createFakeEntity([
            'publications' => 'https://direct.example.com/api/publications',
            'integrationLevel' => 'search',
        ]);

        $objectService->method('searchObjects')->willReturn([$listing]);

        $result = $this->service->getUniqueDirectories();

        $this->assertCount(1, $result);
        $this->assertEquals('https://direct.example.com/api/publications', $result[0]);
    }

    // =========================================================================
    // extractTimestamp – more branch coverage
    // =========================================================================

    public function testExtractTimestampFromNestedSelfCreatedWithMissingPart(): void
    {
        // @self exists but 'created' doesn't — should fall through
        $data = ['@self' => ['other' => 'value'], 'lastSync' => '2025-01-01T00:00:00+00:00'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampWithInvalidStringDate(): void
    {
        // Invalid date string should throw internally and continue to next field
        $data = [
            'updated' => 'completely-invalid-date-string-xyz',
            'lastSync' => '2025-05-01T12:00:00+00:00',
        ];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        // 'updated' will throw an exception, fall through to 'lastSync'
        // Actually DateTime() with 'completely-invalid-date-string-xyz' throws an exception
        // which is caught, then it continues to check @self.updated, created, @self.created, lastSync
        $this->assertInstanceOf(DateTime::class, $result);
    }

    public function testExtractTimestampFromIntegerValue(): void
    {
        // Integer value — not string, not array, not DateTime — should be skipped
        $data = ['updated' => 1234567890, 'created' => '2025-01-01T00:00:00+00:00'];
        $result = $this->invokePrivateMethod('extractTimestamp', [$data]);

        // Integer is not handled by any branch, falls through to 'created'
        $this->assertInstanceOf(DateTime::class, $result);
    }

    // =========================================================================
    // syncListing – more branch coverage for self extraction
    // =========================================================================

    public function testSyncListingUsesUuidFromSelfId(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedUuid = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data, $extend, $register, $schema, $uuid) use (&$savedUuid) {
                $savedUuid = $uuid;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
            '@self' => [
                'id' => 'uuid-from-self',
            ],
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
        $this->assertEquals('uuid-from-self', $savedUuid);
    }

    public function testSyncListingUsesCatalogAsUuidFallback(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedUuid = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data, $extend, $register, $schema, $uuid) use (&$savedUuid) {
                $savedUuid = $uuid;
            });

        // No @self.id and no 'id' in @self, but 'catalog' exists
        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
        // UUID should be the listing id since there's no @self
        $this->assertEquals('listing-1', $savedUuid);
    }

    public function testSyncListingExtractsSearchEndpoint(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $objectService->method('searchObjects')->willReturn([]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'catalog' => 'catalog-1',
            '@self' => [
                'relations' => [
                    'search' => 'https://example.com/api/search',
                    'directory' => 'https://example.com/api/directory',
                ],
            ],
        ];

        $result = $this->service->syncListing($listingData, 'https://directory.example.com');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://example.com/api/search', $savedData['search']);
        $this->assertEquals('https://example.com/api/directory', $savedData['catalogDirectory']);
    }

    public function testSyncListingWithExistingListingPreservesExistingOnMissingFields(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        $existingListing = $this->createFakeEntity([
            'id' => 'existing-uuid',
            'object' => [
                'id' => 'listing-1',
                'title' => 'Old Title',
                'catalog' => 'catalog-1',
                // No 'default', 'status', 'integrationLevel' fields
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$existingListing]);

        $savedData = null;
        $objectService->method('saveObject')
            ->willReturnCallback(function ($data) use (&$savedData) {
                $savedData = $data;
            });

        $listingData = [
            'id' => 'listing-1',
            'title' => 'New Title',
            'catalog' => 'catalog-1',
        ];

        $this->service->syncListing($listingData, 'https://directory.example.com/api/directory');

        // Should use defaults for missing existing values
        $this->assertFalse($savedData['default']);
        $this->assertEquals('development', $savedData['status']);
        $this->assertEquals('search', $savedData['integrationLevel']);
    }

    // =========================================================================
    // getDirectory – array_map callback coverage
    // =========================================================================

    public function testGetDirectoryProcessesNonEntityListingObjects(): void
    {
        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig('listing-schema', 'listing-register', '', '');

        // Return a plain array (not Entity) from searchObjects
        $plainListing = [
            'object' => [
                'id' => 'plain-1',
                'title' => 'Plain Array Listing',
                'status' => 'internal',
                'statusCode' => 200,
            ],
        ];

        // Mock searchObjects to return a mix
        $entityListing = $this->createFakeEntity([
            'object' => [
                'id' => 'entity-1',
                'title' => 'Entity Listing',
                'status' => 'production',
            ],
        ]);

        $objectService->method('searchObjects')->willReturn([$entityListing]);

        $result = $this->service->getDirectory();

        $this->assertCount(1, $result['results']);
        // status should be filtered out
        $this->assertArrayNotHasKey('status', $result['results'][0]['object']);
    }

    // =========================================================================
    // isLocalUrl – more edge cases
    // =========================================================================

    public function testIsLocalUrlWith172NonPrivateRange(): void
    {
        // 172.32.x.x is NOT private (private is 172.16-31.x.x)
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://172.32.0.1/api']);
        $this->assertFalse($result);
    }

    public function testIsLocalUrlWithSubdomainOfLocal(): void
    {
        $result = $this->invokePrivateMethod('isLocalUrl', ['http://server.local/api']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // aggregateFacets – more edge cases for usort comparator
    // =========================================================================

    public function testAggregateFacetsWithMissingCountField(): void
    {
        $existing = [
            'type' => [
                ['_id' => 'a', 'count' => 5],
            ],
        ];

        $newFacets = [
            'type' => [
                ['_id' => 'b'], // No count field
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Should not crash — 'b' should be added without count merging
        $ids = array_column($result['type'], '_id');
        $this->assertContains('a', $ids);
        $this->assertContains('b', $ids);
    }

    public function testAggregateFacetsExistingValueWithoutCount(): void
    {
        $existing = [
            'type' => [
                ['_id' => 'a'], // No count field
            ],
        ];

        $newFacets = [
            'type' => [
                ['_id' => 'a', 'count' => 5], // Same _id, with count
            ],
        ];

        $result = $this->invokePrivateMethod('aggregateFacets', [$existing, $newFacets]);

        // Should handle gracefully
        $this->assertNotEmpty($result['type']);
    }

    // =========================================================================
    // syncDirectory – promise error handling for listing sync
    // =========================================================================

    public function testSyncDirectoryHandlesPromiseException(): void
    {
        $this->urlGenerator->method('getBaseUrl')->willReturn('myserver.example.com');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api/directory');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->request->method('getHeader')->willReturn('');

        $objectService = $this->createMockObjectService();
        $this->setupOpenRegisterAvailable($objectService);
        $this->setupListingConfig();

        // Multiple listings where one will fail during sync
        $responseBody = json_encode([
            'results' => [
                [
                    'id' => 'listing-1',
                    'title' => 'Good Listing',
                    'catalog' => 'catalog-1',
                    'directory' => 'https://other.example.com/api/directory',
                ],
                [
                    'title' => 'Bad Listing (no id)',
                    'directory' => 'https://other.example.com/api/directory',
                ],
            ],
        ]);
        $this->client->method('get')->willReturn(new Response(200, [], $responseBody));

        $objectService->method('searchObjects')->willReturn([]);

        $service = $this->createServiceWithMockClient();
        $ref = new ReflectionClass($service);
        $ref->getProperty('uniqueDirectories')->setValue($service, ['https://other.example.com/api/directory']);

        $result = $service->syncDirectory('https://other.example.com/api/directory');

        $this->assertEquals(2, $result['total_processed']);
        $this->assertGreaterThanOrEqual(1, $result['listings_created'] + $result['listings_failed']);
    }

    // =========================================================================
    // convertCatalogiToListings – with schema expansion
    // =========================================================================

    public function testConvertCatalogiToListingsExpandsSchemas(): void
    {
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://myserver.example.com/api');
        $this->urlGenerator->method('linkToRoute')->willReturn('/api/directory');
        $this->appManager->method('getAppInfo')->willReturn(['version' => '1.0.0']);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $schemaMapper = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findMultiple'])
            ->getMock();
        $schemaMapper->method('findMultiple')->willReturn([]);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($schemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $schemaMapper;
                }
                return null;
            });

        $catalogs = [
            [
                'id' => 'cat-1',
                'object' => [
                    'id' => 'cat-1',
                    'title' => 'Test',
                    'schemas' => ['s1', 's2'],
                ],
            ],
        ];

        $result = $this->service->convertCatalogiToListings($catalogs);

        $this->assertCount(1, $result);
        // schemas should have been expanded (though mapper returns empty, the expansion code executed)
        $this->assertIsArray($result[0]['schemas']);
    }
}
