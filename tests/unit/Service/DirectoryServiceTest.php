<?php

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Test\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class DirectoryServiceTest extends TestCase
{
    private $urlGeneratorMock;
    private $configMock;
    private $objectServiceMock;
    private $clientMock;
    private $directoryService;

    protected function setUp(): void
    {
        $this->urlGeneratorMock = $this->createMock(IURLGenerator::class);
        $this->configMock = $this->createMock(IAppConfig::class);
        $this->objectServiceMock = $this->createMock(ObjectService::class);

        $this->configMock->method('getValueString')
            // ->willReturnMap([
            //     ['opencatalogi', 'mongodbLocation', 'http://localhost'],
            //     ['opencatalogi', 'mongodbKey', 'key'],
            //     ['opencatalogi', 'mongodbCluster', 'cluster']
            // ]);
            ->will($this->returnValueMap([
                ['opencatalogi', 'mongodbLocation', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', 'key'],
                ['opencatalogi', 'mongodbCluster', 'cluster']
            ]));

        // Debugging: check the parameters passed to the mocked method
        $location = $this->configMock->getValueString('opencatalogi', 'mongodbLocation');
        $key = $this->configMock->getValueString('opencatalogi', 'mongodbKey');
        $cluster = $this->configMock->getValueString('opencatalogi', 'mongodbCluster');

        print_r($location); // Should output 'http://localhost'
        print_r($key);      // Should output 'key'
        print_r($cluster);  // Should output 'cluster'

        $this->assertEquals('http://localhost', $location);
        $this->assertEquals('key', $key);
        $this->assertEquals('cluster', $cluster);

        $this->directoryService = new DirectoryService(
            $this->urlGeneratorMock,
            $this->configMock,
            $this->objectServiceMock
        );

        // Use reflection to set the private $client property
        $this->clientMock = $this->createMock(Client::class);

        $reflection = new ReflectionClass($this->directoryService);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->directoryService, $this->clientMock);
    }

    public function testRegisterToExternalDirectory()
    {
        $newDirectory = ['directory' => 'https://example.com/directory'];
        $dbConfig = [
            'base_uri' => 'http://localhost',
            'headers' => ['api-key' => 'key'],
            'mongodbCluster' => 'cluster'
        ];

        $catalogi = [['id' => 'catalog1'], ['id' => 'catalog2']];

        $this->objectServiceMock->method('findObjects')
            ->with(['_schema' => 'catalog'], $dbConfig)
            ->willReturn(['documents' => $catalogi]);

        $this->clientMock->method('post')
            ->willReturn(new Response(200));

		$this->client->method('get')
			->willReturn(new Response(status: 200, body: '{"results": []}'));

        $statusCode = $this->directoryService->registerToExternalDirectory($newDirectory);

        $this->assertEquals(200, $statusCode);
    }

    public function testFetchFromExternalDirectory()
    {
        $directory = ['directory' => 'https://example.com/directory'];
        $responseBody = json_encode(['results' => [['directory' => 'https://example.com/dir1'], ['directory' => 'https://example.com/dir2']]]);
        $responseBody2 = json_encode(['results' => [['directory' => 'https://example.com/directory']]]);
        $response = new Response(status: 200, body: $responseBody);

        $this->clientMock->method('get')
            ->with($directory['directory'])
            ->willReturn($response);

		$this->objectService->method('findObjects')
			->willReturn(['documents' => []]);

        $results = $this->directoryService->fetchFromExternalDirectory($directory);

        $this->assertCount(2, $results);
    }

    public function testCreateDirectoryFromResult()
    {
        $result = [
            'directory' => 'https://example.com/directory',
            '_schema' => 'directory'
        ];
        $dbConfig = [
            'base_uri' => 'http://localhost',
            'headers' => ['api-key' => 'key'],
            'mongodbCluster' => 'cluster'
        ];

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', 'key'],
                ['opencatalogi', 'mongodbCluster', 'cluster']
            ]);

        $this->objectService->method('saveObject')
            ->with($result, $dbConfig)
            ->willReturn($result);

        $this->directoryService->method('registerToExternalDirectory')
            ->with($result)
            ->willReturn(200);

        $createdDirectory = $this->invokeMethod($this->directoryService, 'createDirectoryFromResult', [$result]);

        $this->assertNotNull($createdDirectory);
        $this->assertEquals($result, $createdDirectory);
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Test getPublications method with successful results
     *
     * @return void
     */
    public function testGetPublicationsSuccess()
    {
        // Mock configuration values
        $this->configMock->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'listing_schema', 'test-schema'],
                ['opencatalogi', 'listing_register', 'test-register'],
                ['opencatalogi', 'mongodbLocation', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', 'key'],
                ['opencatalogi', 'mongodbCluster', 'cluster']
            ]);

        // Mock listings with publication endpoints
        $mockListings = [
            (object)[
                'id' => 'listing-1',
                'object' => [
                    'title' => 'Test Catalog 1',
                    'catalogusId' => 'catalog-1',
                    'publications' => 'https://api.example.com/publications',
                    'default' => true,
                    'available' => true
                ]
            ],
            (object)[
                'id' => 'listing-2',
                'object' => [
                    'title' => 'Test Catalog 2',
                    'catalogusId' => 'catalog-2',
                    'publications' => 'https://api.example2.com/publications',
                    'default' => true,
                    'available' => true
                ]
            ]
        ];

        // Mock ObjectService to return mock listings
        $this->objectServiceMock->method('findAll')
            ->willReturn($mockListings);

        // Mock successful HTTP responses
        $publicationsResponse1 = [
            'results' => [
                ['id' => 'pub-1', 'title' => 'Publication 1'],
                ['id' => 'pub-2', 'title' => 'Publication 2']
            ]
        ];
        $publicationsResponse2 = [
            'results' => [
                ['id' => 'pub-3', 'title' => 'Publication 3']
            ]
        ];

        $this->clientMock->method('get')
            ->willReturnMap([
                ['https://api.example.com/publications', new Response(200, [], json_encode($publicationsResponse1))],
                ['https://api.example2.com/publications', new Response(200, [], json_encode($publicationsResponse2))]
            ]);

        // Test the method
        $result = $this->directoryService->getPublications();

        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('statistics', $result);

        // Check that results are combined
        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['results']);

        // Check that source information is added
        foreach ($result['results'] as $publication) {
            $this->assertArrayHasKey('_source', $publication);
            $this->assertArrayHasKey('endpoint', $publication['_source']);
            $this->assertArrayHasKey('listing_title', $publication['_source']);
        }

        // Check statistics
        $this->assertEquals(2, $result['statistics']['total_endpoints']);
        $this->assertEquals(2, $result['statistics']['successful_calls']);
        $this->assertEquals(0, $result['statistics']['failed_calls']);
        $this->assertEquals(3, $result['statistics']['total_publications']);
    }

    /**
     * Test getPublications method with mixed success and failure
     *
     * @return void
     */
    public function testGetPublicationsMixedResults()
    {
        // Mock configuration values
        $this->configMock->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'listing_schema', 'test-schema'],
                ['opencatalogi', 'listing_register', 'test-register']
            ]);

        // Mock listings with publication endpoints
        $mockListings = [
            (object)[
                'id' => 'listing-1',
                'object' => [
                    'title' => 'Working Catalog',
                    'catalogusId' => 'catalog-1',
                    'publications' => 'https://api.working.com/publications',
                    'default' => true,
                    'available' => true
                ]
            ],
            (object)[
                'id' => 'listing-2',
                'object' => [
                    'title' => 'Broken Catalog',
                    'catalogusId' => 'catalog-2',
                    'publications' => 'https://api.broken.com/publications',
                    'default' => true,
                    'available' => true
                ]
            ]
        ];

        $this->objectServiceMock->method('findAll')
            ->willReturn($mockListings);

        // Mock mixed HTTP responses
        $successResponse = ['results' => [['id' => 'pub-1', 'title' => 'Working Publication']]];
        
        $this->clientMock->method('get')
            ->willReturnMap([
                ['https://api.working.com/publications', new Response(200, [], json_encode($successResponse))],
                ['https://api.broken.com/publications', new Response(500, [], 'Internal Server Error')]
            ]);

        // Test the method
        $result = $this->directoryService->getPublications();

        // Assertions
        $this->assertEquals(1, $result['total']); // Only successful results
        $this->assertEquals(2, $result['statistics']['total_endpoints']);
        $this->assertEquals(1, $result['statistics']['successful_calls']);
        $this->assertEquals(1, $result['statistics']['failed_calls']);
        $this->assertCount(1, $result['errors']); // One error recorded
    }

    /**
     * Test getPublications method with no default listings
     *
     * @return void
     */
    public function testGetPublicationsNoDefaultListings()
    {
        // Mock configuration values
        $this->configMock->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'listing_schema', 'test-schema'],
                ['opencatalogi', 'listing_register', 'test-register']
            ]);

        // Mock empty listings result
        $this->objectServiceMock->method('findAll')
            ->willReturn([]);

        // Test the method
        $result = $this->directoryService->getPublications();

        // Assertions
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['results']);
        $this->assertEquals(0, $result['statistics']['total_endpoints']);
        $this->assertEquals(0, $result['statistics']['successful_calls']);
        $this->assertEquals(0, $result['statistics']['failed_calls']);
    }

    /**
     * Test getPublications method with custom Guzzle configuration
     *
     * @return void
     */
    public function testGetPublicationsWithCustomGuzzleConfig()
    {
        // Mock configuration values
        $this->configMock->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'listing_schema', 'test-schema'],
                ['opencatalogi', 'listing_register', 'test-register']
            ]);

        // Mock listings
        $mockListings = [
            (object)[
                'id' => 'listing-1',
                'object' => [
                    'title' => 'Test Catalog',
                    'publications' => 'https://api.example.com/publications',
                    'default' => true,
                    'available' => true
                ]
            ]
        ];

        $this->objectServiceMock->method('findAll')
            ->willReturn($mockListings);

        $successResponse = ['results' => [['id' => 'pub-1', 'title' => 'Test Publication']]];
        $this->clientMock->method('get')
            ->willReturn(new Response(200, [], json_encode($successResponse)));

        // Test with custom Guzzle configuration
        $customConfig = [
            'timeout' => 60,
            'connect_timeout' => 15,
            'headers' => ['Custom-Header' => 'test-value']
        ];

        $result = $this->directoryService->getPublications($customConfig);

        // Assertions
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['statistics']['successful_calls']);
    }

    /**
     * Test updateListingStatus method
     *
     * @return void
     */
    public function testUpdateListingStatus()
    {
        // Mock existing listing
        $existingListing = (object)[
            'id' => 'listing-1',
            'object' => [
                'title' => 'Test Catalog',
                'statusCode' => 0,
                'available' => false
            ]
        ];

        $this->objectServiceMock->method('findAll')
            ->willReturn([$existingListing]);

        $this->objectServiceMock->expects($this->once())
            ->method('saveObject');

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->directoryService);
        $method = $reflection->getMethod('updateListingStatus');
        $method->setAccessible(true);

        // Test the method
        $method->invoke(
            $this->directoryService,
            $this->objectServiceMock,
            'test-register',
            'test-schema',
            'listing-1',
            200,
            true
        );

        // The method should have been called - we can't easily assert the exact parameters
        // but we can verify the saveObject method was called once
        $this->addToAssertionCount(1);
    }
}
