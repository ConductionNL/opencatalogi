<?php

declare(strict_types=1);

namespace Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use OCA\OpenCatalogi\Service\SearchService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SearchServiceTest extends TestCase
{

    private SearchService $searchService;
    private ElasticSearchService $elasticServiceMock;
    private DirectoryService $directoryServiceMock;
    private IURLGenerator $urlGeneratorMock;

    protected function setUp(): void
    {
        $this->elasticServiceMock = $this->createMock(ElasticSearchService::class);
        $this->directoryServiceMock = $this->createMock(DirectoryService::class);
        $this->urlGeneratorMock = $this->createMock(IURLGenerator::class);

        $this->searchService = new SearchService(
            $this->elasticServiceMock,
            $this->directoryServiceMock,
            $this->urlGeneratorMock
        );
    }

    // =========================================================================
    // mergeFacets
    // =========================================================================

    public function testMergeFacetsSuccess(): void
    {
        $existing = [
            ['_id' => 'cat1', 'count' => 10],
            ['_id' => 'cat2', 'count' => 5],
        ];
        $new = [
            ['_id' => 'cat1', 'count' => 3],
            ['_id' => 'cat3', 'count' => 7],
        ];

        $result = $this->searchService->mergeFacets($existing, $new);

        // cat1 overlaps so merged count = 13 => removed by array_diff (present in both maps)
        // cat2 only in existing => in diff
        // cat3 only in new => in diff
        $this->assertIsArray($result);

        $resultMap = [];
        foreach ($result as $item) {
            $resultMap[$item['_id']] = $item['count'];
        }

        $this->assertArrayHasKey('cat2', $resultMap);
        $this->assertEquals(5, $resultMap['cat2']);
        $this->assertArrayHasKey('cat3', $resultMap);
        $this->assertEquals(7, $resultMap['cat3']);
    }

    public function testMergeFacetsEmptyExisting(): void
    {
        $existing = [];
        $new = [
            ['_id' => 'cat1', 'count' => 5],
        ];

        $result = $this->searchService->mergeFacets($existing, $new);

        $this->assertCount(1, $result);
        $this->assertEquals('cat1', $result[0]['_id']);
        $this->assertEquals(5, $result[0]['count']);
    }

    public function testMergeFacetsEmptyNew(): void
    {
        $existing = [
            ['_id' => 'cat1', 'count' => 10],
        ];
        $new = [];

        $result = $this->searchService->mergeFacets($existing, $new);

        $this->assertCount(1, $result);
        $this->assertEquals('cat1', $result[0]['_id']);
        $this->assertEquals(10, $result[0]['count']);
    }

    public function testMergeFacetsBothEmpty(): void
    {
        $result = $this->searchService->mergeFacets([], []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMergeFacetsIdenticalAggregations(): void
    {
        $data = [
            ['_id' => 'cat1', 'count' => 5],
        ];

        $result = $this->searchService->mergeFacets($data, $data);

        // When both have same _id with same count: merged = 10, existing = 5
        // array_diff(existing, newMapped) where existing[cat1]=5, newMapped[cat1]=10 => cat1 in diff
        // array_diff(newMapped, existing) where newMapped[cat1]=10, existing[cat1]=5 => cat1 in diff
        // array_merge of diffs: both have 'cat1', second overwrites first
        $resultMap = [];
        foreach ($result as $item) {
            $resultMap[$item['_id']] = $item['count'];
        }

        $this->assertArrayHasKey('cat1', $resultMap);
    }

    // =========================================================================
    // sortResultArray
    // =========================================================================

    public function testSortResultArrayLessThan(): void
    {
        $a = ['_score' => 1];
        $b = ['_score' => 2];
        $this->assertEquals(-1, $this->searchService->sortResultArray($a, $b));
    }

    public function testSortResultArrayGreaterThan(): void
    {
        $a = ['_score' => 5];
        $b = ['_score' => 2];
        $this->assertEquals(1, $this->searchService->sortResultArray($a, $b));
    }

    public function testSortResultArrayEqual(): void
    {
        $a = ['_score' => 3];
        $this->assertEquals(0, $this->searchService->sortResultArray($a, $a));
    }

    public function testSortResultArrayZeroScores(): void
    {
        $a = ['_score' => 0];
        $b = ['_score' => 0];
        $this->assertEquals(0, $this->searchService->sortResultArray($a, $b));
    }

    public function testSortResultArrayNegativeScores(): void
    {
        $a = ['_score' => -5];
        $b = ['_score' => -2];
        $this->assertEquals(-1, $this->searchService->sortResultArray($a, $b));
    }

    public function testSortResultArrayFloatScores(): void
    {
        $a = ['_score' => 1.5];
        $b = ['_score' => 1.6];
        $this->assertEquals(-1, $this->searchService->sortResultArray($a, $b));
    }

    // =========================================================================
    // search
    // =========================================================================

    public function testSearchWithEmptyElasticConfigLocation(): void
    {
        $this->directoryServiceMock->method('getDirectory')->willReturn([]);

        $this->elasticServiceMock->expects($this->never())->method('searchObject');

        $result = $this->searchService->search(
            [],
            ['location' => '', 'key' => '', 'index' => ''],
            []
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(0, $result['count']);
        $this->assertEquals(30, $result['limit']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(1, $result['pages']);
        $this->assertEquals(0, $result['total']);
    }

    public function testSearchWithElasticConfigAndEmptyDirectory(): void
    {
        $elasticConfig = [
            'location' => 'https://elastic.example.com',
            'key' => 'dXNlcm5hbWU6cGFzc3dvcmQ=',
            'index' => 'objects',
        ];

        $localResults = [
            'results' => [
                ['_score' => 1, 'id' => 1],
                ['_score' => 2, 'id' => 2],
            ],
            'facets' => [
                'category' => [
                    ['_id' => 'cat1', 'count' => 10],
                ],
            ],
        ];

        $totalResults = 0;

        $this->elasticServiceMock
            ->method('searchObject')
            ->willReturn($localResults);

        $this->directoryServiceMock
            ->method('getDirectory')
            ->willReturn([]);

        $result = $this->searchService->search([], $elasticConfig, []);

        $this->assertIsArray($result);
        $this->assertEquals($localResults['results'], $result['results']);
        $this->assertEquals($localResults['facets'], $result['facets']);
        $this->assertEquals(2, $result['count']);
    }

    public function testSearchWithCustomLimitAndPage(): void
    {
        $this->directoryServiceMock->method('getDirectory')->willReturn([]);
        $this->elasticServiceMock->method('searchObject')->willReturn([
            'results' => [],
            'facets' => [],
        ]);

        $result = $this->searchService->search(
            ['_limit' => 10, '_page' => 3],
            ['location' => 'https://elastic.example.com', 'key' => '', 'index' => ''],
            []
        );

        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(3, $result['page']);
    }

    public function testSearchWithDirectoryEntries(): void
    {
        $elasticConfig = [
            'location' => 'https://elastic.example.com',
            'key' => 'key',
            'index' => 'objects',
        ];

        $this->elasticServiceMock
            ->method('searchObject')
            ->willReturn([
                'results' => [['_score' => 1, 'id' => 'local1']],
                'facets' => [],
            ]);

        $this->urlGeneratorMock
            ->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock
            ->method('getAbsoluteURL')
            ->willReturn('http://localhost/apps/opencatalogi/api/directory');

        $this->directoryServiceMock
            ->method('getDirectory')
            ->willReturn([
                [
                    'default' => true,
                    'search' => 'http://remote.example.com/search',
                    'catalog' => 'remote-catalog-1',
                ],
            ]);

        // Mock the Guzzle client to return a fulfilled promise
        $responseBody = json_encode([
            'results' => [['_score' => 2, 'id' => 'remote1']],
            'facets' => [],
        ]);
        $promiseMock = new FulfilledPromise(new Response(200, [], $responseBody));

        $clientMock = $this->createMock(Client::class);
        $clientMock->expects($this->once())
            ->method('getAsync')
            ->willReturn($promiseMock);

        $reflection = new ReflectionClass(SearchService::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->searchService, $clientMock);

        $result = $this->searchService->search([], $elasticConfig, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertEquals(2, $result['count']);
    }

    public function testSearchSkipsDirectoryWithDefaultFalse(): void
    {
        $elasticConfig = [
            'location' => 'https://elastic.example.com',
            'key' => 'key',
            'index' => 'objects',
        ];

        $this->elasticServiceMock
            ->method('searchObject')
            ->willReturn(['results' => [], 'facets' => []]);

        $this->urlGeneratorMock
            ->method('linkToRoute')
            ->willReturn('/api/directory');
        $this->urlGeneratorMock
            ->method('getAbsoluteURL')
            ->willReturn('http://localhost/api/directory');

        $this->directoryServiceMock
            ->method('getDirectory')
            ->willReturn([
                [
                    'default' => false,
                    'search' => 'http://remote.example.com/search',
                    'catalog' => 'skipped-catalog',
                ],
            ]);

        $clientMock = $this->createMock(Client::class);
        $clientMock->expects($this->never())->method('getAsync');

        $reflection = new ReflectionClass(SearchService::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->searchService, $clientMock);

        $result = $this->searchService->search([], $elasticConfig, []);

        $this->assertEquals(0, $result['count']);
    }

    public function testSearchSkipsSelfDirectory(): void
    {
        $elasticConfig = [
            'location' => 'https://elastic.example.com',
            'key' => 'key',
            'index' => 'objects',
        ];

        $this->elasticServiceMock
            ->method('searchObject')
            ->willReturn(['results' => [], 'facets' => []]);

        $this->urlGeneratorMock
            ->method('linkToRoute')
            ->willReturn('/api/directory');
        $this->urlGeneratorMock
            ->method('getAbsoluteURL')
            ->willReturn('http://localhost/api/directory');

        // Directory entry with search URL matching self
        $this->directoryServiceMock
            ->method('getDirectory')
            ->willReturn([
                [
                    'default' => true,
                    'search' => 'http://localhost/api/directory',
                    'catalog' => 'self-catalog',
                ],
            ]);

        $clientMock = $this->createMock(Client::class);
        $clientMock->expects($this->never())->method('getAsync');

        $reflection = new ReflectionClass(SearchService::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->searchService, $clientMock);

        $result = $this->searchService->search([], $elasticConfig, []);

        $this->assertEquals(0, $result['count']);
    }

    public function testSearchWithRejectedPromise(): void
    {
        $elasticConfig = [
            'location' => 'https://elastic.example.com',
            'key' => 'key',
            'index' => 'objects',
        ];

        $this->elasticServiceMock
            ->method('searchObject')
            ->willReturn(['results' => [], 'facets' => []]);

        $this->urlGeneratorMock
            ->method('linkToRoute')
            ->willReturn('/api/directory');
        $this->urlGeneratorMock
            ->method('getAbsoluteURL')
            ->willReturn('http://localhost/api/directory');

        $this->directoryServiceMock
            ->method('getDirectory')
            ->willReturn([
                [
                    'default' => true,
                    'search' => 'http://remote.example.com/search',
                    'catalog' => 'remote-catalog',
                ],
            ]);

        $promiseMock = new RejectedPromise(new \Exception('Connection refused'));

        $clientMock = $this->createMock(Client::class);
        $clientMock->method('getAsync')->willReturn($promiseMock);

        $reflection = new ReflectionClass(SearchService::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->searchService, $clientMock);

        $result = $this->searchService->search([], $elasticConfig, []);

        // Rejected promises should be ignored, returning only local results
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['count']);
    }

    // =========================================================================
    // createMongoDBSearchFilter
    // =========================================================================

    public function testCreateMongoDBSearchFilterWithSearch(): void
    {
        $filters = ['_search' => 'test query'];
        $fields = ['title', 'description'];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fields);

        $this->assertArrayHasKey('$or', $result);
        $this->assertCount(2, $result['$or']);
        $this->assertEquals(
            ['title' => ['$regex' => 'test query', '$options' => 'i']],
            $result['$or'][0]
        );
        $this->assertEquals(
            ['description' => ['$regex' => 'test query', '$options' => 'i']],
            $result['$or'][1]
        );
        $this->assertArrayNotHasKey('_search', $result);
    }

    public function testCreateMongoDBSearchFilterWithoutSearch(): void
    {
        $filters = ['status' => 'active'];
        $fields = ['title'];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fields);

        $this->assertArrayNotHasKey('$or', $result);
        $this->assertEquals('active', $result['status']);
    }

    public function testCreateMongoDBSearchFilterIsNotNull(): void
    {
        $filters = ['title' => 'IS NOT NULL'];
        $fields = [];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fields);

        $this->assertEquals(['$ne' => null], $result['title']);
    }

    public function testCreateMongoDBSearchFilterIsNull(): void
    {
        $filters = ['title' => 'IS NULL'];
        $fields = [];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fields);

        $this->assertEquals(['$eq' => null], $result['title']);
    }

    public function testCreateMongoDBSearchFilterEmptyFilters(): void
    {
        $result = $this->searchService->createMongoDBSearchFilter([], []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateMongoDBSearchFilterEmptyFieldsToSearch(): void
    {
        $filters = ['_search' => 'test'];
        $result = $this->searchService->createMongoDBSearchFilter($filters, []);

        $this->assertArrayHasKey('$or', $result);
        $this->assertEmpty($result['$or']);
        $this->assertArrayNotHasKey('_search', $result);
    }

    public function testCreateMongoDBSearchFilterCombinedSearchAndNullChecks(): void
    {
        $filters = [
            '_search' => 'query',
            'status' => 'IS NOT NULL',
            'archived' => 'IS NULL',
            'category' => 'news',
        ];
        $fields = ['title'];

        $result = $this->searchService->createMongoDBSearchFilter($filters, $fields);

        $this->assertArrayHasKey('$or', $result);
        $this->assertEquals(['$ne' => null], $result['status']);
        $this->assertEquals(['$eq' => null], $result['archived']);
        $this->assertEquals('news', $result['category']);
    }

    // =========================================================================
    // createMySQLSearchConditions
    // =========================================================================

    public function testCreateMySQLSearchConditionsWithSearch(): void
    {
        $filters = ['_search' => 'test'];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            ['title', 'description'],
            $searchParams
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('LOWER(title) LIKE :search', $result[0]);
        $this->assertStringContainsString('LOWER(description) LIKE :search', $result[0]);
        $this->assertStringStartsWith('(', $result[0]);
        $this->assertStringEndsWith(')', $result[0]);
    }

    public function testCreateMySQLSearchConditionsWithSingleField(): void
    {
        $filters = ['_search' => 'test'];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            ['title'],
            $searchParams
        );

        $this->assertCount(1, $result);
        $this->assertEquals('(LOWER(title) LIKE :search)', $result[0]);
    }

    public function testCreateMySQLSearchConditionsWithCommaValues(): void
    {
        $filters = ['status' => 'active,archived,draft'];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            [],
            $searchParams
        );

        // Should contain the OR condition
        $found = false;
        foreach ($result as $condition) {
            if (strpos($condition, 'status = :status_0') !== false) {
                $found = true;
                $this->assertStringContainsString('status = :status_1', $condition);
                $this->assertStringContainsString('status = :status_2', $condition);
            }
        }

        $this->assertTrue($found, 'Comma-separated values should generate OR conditions');
        $this->assertEquals('active', $searchParams['status_0']);
        $this->assertEquals('archived', $searchParams['status_1']);
        $this->assertEquals('draft', $searchParams['status_2']);

        // The original filter key should be unset
        $this->assertArrayNotHasKey('status', $filters);
    }

    public function testCreateMySQLSearchConditionsNoFilters(): void
    {
        $filters = [];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            [],
            $searchParams
        );

        $this->assertCount(1, $result);
        $this->assertEquals('1=1', $result[0]);
    }

    public function testCreateMySQLSearchConditionsSkipsEmptyValues(): void
    {
        $filters = ['status' => '', 'title' => null];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            [],
            $searchParams
        );

        $this->assertCount(1, $result);
        $this->assertEquals('1=1', $result[0]);
    }

    public function testCreateMySQLSearchConditionsSearchAndCommaFilters(): void
    {
        $filters = ['_search' => 'test', 'category' => 'news,events'];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            ['title'],
            $searchParams
        );

        $this->assertCount(2, $result);
        $this->assertStringContainsString('LOWER(title) LIKE :search', $result[0]);
        $this->assertStringContainsString('category = :category_0', $result[1]);
    }

    public function testCreateMySQLSearchConditionsSkipsSearchKey(): void
    {
        $filters = ['_search' => 'test', 'name' => 'value'];
        $searchParams = [];

        $result = $this->searchService->createMySQLSearchConditions(
            $filters,
            ['title'],
            $searchParams
        );

        // _search should be skipped in the foreach, name is a simple value (no comma)
        // so only search condition + default 1=1 would not apply since we have conditions
        $this->assertGreaterThanOrEqual(1, count($result));
    }

    // =========================================================================
    // unsetSpecialQueryParams
    // =========================================================================

    public function testUnsetSpecialQueryParamsRemovesUnderscoreKeys(): void
    {
        $filters = [
            '_limit' => 10,
            '_page' => 1,
            '_search' => 'test',
            '_order' => ['title' => 'ASC'],
            'title' => 'hello',
            'category' => 'news',
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);

        $this->assertArrayNotHasKey('_limit', $result);
        $this->assertArrayNotHasKey('_page', $result);
        $this->assertArrayNotHasKey('_search', $result);
        $this->assertArrayNotHasKey('_order', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('category', $result);
    }

    public function testUnsetSpecialQueryParamsRemovesSearchKey(): void
    {
        $filters = [
            'search' => 'test',
            'title' => 'hello',
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);

        $this->assertArrayNotHasKey('search', $result);
        $this->assertArrayHasKey('title', $result);
    }

    public function testUnsetSpecialQueryParamsEmptyArray(): void
    {
        $result = $this->searchService->unsetSpecialQueryParams([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testUnsetSpecialQueryParamsAllSpecial(): void
    {
        $filters = [
            '_limit' => 10,
            '_page' => 1,
            'search' => 'query',
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);
        $this->assertEmpty($result);
    }

    public function testUnsetSpecialQueryParamsNoSpecial(): void
    {
        $filters = [
            'title' => 'hello',
            'category' => 'news',
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);
        $this->assertEquals($filters, $result);
    }

    // =========================================================================
    // createMySQLSearchParams
    // =========================================================================

    public function testCreateMySQLSearchParamsWithSearch(): void
    {
        $filters = ['_search' => 'Test Query'];

        $result = $this->searchService->createMySQLSearchParams($filters);

        $this->assertArrayHasKey('search', $result);
        $this->assertEquals('%test query%', $result['search']);
    }

    public function testCreateMySQLSearchParamsWithoutSearch(): void
    {
        $filters = ['title' => 'hello'];

        $result = $this->searchService->createMySQLSearchParams($filters);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateMySQLSearchParamsEmptyFilters(): void
    {
        $result = $this->searchService->createMySQLSearchParams([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateMySQLSearchParamsEmptySearchString(): void
    {
        $filters = ['_search' => ''];

        $result = $this->searchService->createMySQLSearchParams($filters);

        // empty string is falsy, so isset returns true but value is ''
        $this->assertArrayHasKey('search', $result);
        $this->assertEquals('%%', $result['search']);
    }

    public function testCreateMySQLSearchParamsSpecialCharacters(): void
    {
        $filters = ['_search' => "O'Brien & Co."];

        $result = $this->searchService->createMySQLSearchParams($filters);

        $this->assertEquals("%o'brien & co.%", $result['search']);
    }

    public function testCreateMySQLSearchParamsUpperCase(): void
    {
        $filters = ['_search' => 'UPPERCASE'];

        $result = $this->searchService->createMySQLSearchParams($filters);

        $this->assertEquals('%uppercase%', $result['search']);
    }

    // =========================================================================
    // createSortForMySQL
    // =========================================================================

    public function testCreateSortForMySQLWithOrder(): void
    {
        $filters = [
            '_order' => [
                'title' => 'ASC',
                'date' => 'DESC',
            ],
        ];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertEquals('ASC', $result['title']);
        $this->assertEquals('DESC', $result['date']);
    }

    public function testCreateSortForMySQLDefaultsToAsc(): void
    {
        $filters = [
            '_order' => [
                'title' => 'invalid',
            ],
        ];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertEquals('ASC', $result['title']);
    }

    public function testCreateSortForMySQLCaseInsensitiveDesc(): void
    {
        $filters = [
            '_order' => [
                'title' => 'desc',
                'name' => 'Desc',
            ],
        ];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertEquals('DESC', $result['title']);
        $this->assertEquals('DESC', $result['name']);
    }

    public function testCreateSortForMySQLNoOrder(): void
    {
        $filters = ['title' => 'test'];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateSortForMySQLEmptyFilters(): void
    {
        $result = $this->searchService->createSortForMySQL([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateSortForMySQLOrderNotArray(): void
    {
        $filters = ['_order' => 'title'];

        $result = $this->searchService->createSortForMySQL($filters);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // createSortForMongoDB
    // =========================================================================

    public function testCreateSortForMongoDBWithOrder(): void
    {
        $filters = [
            '_order' => [
                'title' => 'ASC',
                'date' => 'DESC',
            ],
        ];

        $result = $this->searchService->createSortForMongoDB($filters);

        $this->assertEquals(1, $result['title']);
        $this->assertEquals(-1, $result['date']);
    }

    public function testCreateSortForMongoDBDefaultsToAsc(): void
    {
        $filters = [
            '_order' => [
                'title' => 'something',
            ],
        ];

        $result = $this->searchService->createSortForMongoDB($filters);

        $this->assertEquals(1, $result['title']);
    }

    public function testCreateSortForMongoDBCaseInsensitiveDesc(): void
    {
        $filters = [
            '_order' => [
                'title' => 'desc',
                'name' => 'Desc',
            ],
        ];

        $result = $this->searchService->createSortForMongoDB($filters);

        $this->assertEquals(-1, $result['title']);
        $this->assertEquals(-1, $result['name']);
    }

    public function testCreateSortForMongoDBNoOrder(): void
    {
        $result = $this->searchService->createSortForMongoDB(['title' => 'test']);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateSortForMongoDBEmptyFilters(): void
    {
        $result = $this->searchService->createSortForMongoDB([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateSortForMongoDBOrderNotArray(): void
    {
        $result = $this->searchService->createSortForMongoDB(['_order' => 'title']);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // parseQueryString
    // =========================================================================

    public function testParseQueryStringSimple(): void
    {
        $result = $this->searchService->parseQueryString('foo=bar&baz=qux');

        $this->assertEquals('bar', $result['foo']);
        $this->assertEquals('qux', $result['baz']);
    }

    public function testParseQueryStringEmpty(): void
    {
        $result = $this->searchService->parseQueryString('');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseQueryStringNoValue(): void
    {
        $result = $this->searchService->parseQueryString('foo=');

        $this->assertArrayHasKey('foo', $result);
        $this->assertEquals('', $result['foo']);
    }

    public function testParseQueryStringUrlEncoded(): void
    {
        $result = $this->searchService->parseQueryString('name=John%20Doe&city=New%20York');

        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals('New York', $result['city']);
    }

    public function testParseQueryStringWithBrackets(): void
    {
        $result = $this->searchService->parseQueryString('_order[title]=ASC&_order[date]=DESC');

        $this->assertArrayHasKey('_order', $result);
        $this->assertIsArray($result['_order']);
        $this->assertEquals('ASC', $result['_order']['title']);
        $this->assertEquals('DESC', $result['_order']['date']);
    }

    public function testParseQueryStringWithArrayBrackets(): void
    {
        $result = $this->searchService->parseQueryString('tags[]=php&tags[]=javascript');

        $this->assertArrayHasKey('tags', $result);
        $this->assertIsArray($result['tags']);
        $this->assertCount(2, $result['tags']);
        $this->assertContains('php', $result['tags']);
        $this->assertContains('javascript', $result['tags']);
    }

    public function testParseQueryStringNestedBrackets(): void
    {
        $result = $this->searchService->parseQueryString('filter[status][type]=active');

        $this->assertArrayHasKey('filter', $result);
        $this->assertIsArray($result['filter']);
        $this->assertArrayHasKey('status', $result['filter']);
        $this->assertIsArray($result['filter']['status']);
        $this->assertEquals('active', $result['filter']['status']['type']);
    }

    public function testParseQueryStringMixedParams(): void
    {
        $result = $this->searchService->parseQueryString(
            '_search=test&_limit=10&_order[title]=ASC&category=news'
        );

        $this->assertEquals('test', $result['_search']);
        $this->assertEquals('10', $result['_limit']);
        $this->assertIsArray($result['_order']);
        $this->assertEquals('ASC', $result['_order']['title']);
        $this->assertEquals('news', $result['category']);
    }

    public function testParseQueryStringDefaultEmpty(): void
    {
        $result = $this->searchService->parseQueryString();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseQueryStringKeyWithoutEquals(): void
    {
        $result = $this->searchService->parseQueryString('foo&bar=baz');

        $this->assertArrayHasKey('foo', $result);
        $this->assertEquals('', $result['foo']);
        $this->assertEquals('baz', $result['bar']);
    }

    // =========================================================================
    // mergeAggregations (private, via reflection)
    // =========================================================================

    public function testMergeAggregationsNullNew(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $result = $method->invoke($this->searchService, ['key' => []], null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMergeAggregationsNullExisting(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $newAgg = [
            'category' => [
                ['_id' => 'cat1', 'count' => 5],
            ],
        ];

        $result = $method->invoke($this->searchService, null, $newAgg);

        $this->assertArrayHasKey('category', $result);
        $this->assertCount(1, $result['category']);
    }

    public function testMergeAggregationsBothNull(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $result = $method->invoke($this->searchService, null, null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMergeAggregationsNewKeyNotInExisting(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $existing = [
            'status' => [
                ['_id' => 'active', 'count' => 3],
            ],
        ];

        $new = [
            'category' => [
                ['_id' => 'news', 'count' => 7],
            ],
        ];

        $result = $method->invoke($this->searchService, $existing, $new);

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('category', $result);
    }

    public function testMergeAggregationsOverlappingKeys(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $existing = [
            'category' => [
                ['_id' => 'news', 'count' => 3],
            ],
        ];

        $new = [
            'category' => [
                ['_id' => 'events', 'count' => 5],
            ],
        ];

        $result = $method->invoke($this->searchService, $existing, $new);

        $this->assertArrayHasKey('category', $result);
        // mergeFacets is called for overlapping keys
        $this->assertIsArray($result['category']);
    }

    public function testMergeAggregationsEmptyArrays(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'mergeAggregations');
        $method->setAccessible(true);

        $result = $method->invoke($this->searchService, [], []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // recursiveRequestQueryKey (private, via reflection)
    // =========================================================================

    public function testRecursiveRequestQueryKeySimple(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'recursiveRequestQueryKey');
        $method->setAccessible(true);

        $vars = [];
        $method->invokeArgs($this->searchService, [&$vars, 'foo', 'foo', 'bar']);

        $this->assertEquals('bar', $vars['foo']);
    }

    public function testRecursiveRequestQueryKeyWithBrackets(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'recursiveRequestQueryKey');
        $method->setAccessible(true);

        $vars = [];
        $method->invokeArgs($this->searchService, [&$vars, '_order[title]', '_order', 'ASC']);

        $this->assertIsArray($vars['_order']);
        $this->assertEquals('ASC', $vars['_order']['title']);
    }

    public function testRecursiveRequestQueryKeyWithEmptyBrackets(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'recursiveRequestQueryKey');
        $method->setAccessible(true);

        $vars = [];
        $method->invokeArgs($this->searchService, [&$vars, 'tags[]', 'tags', 'php']);

        $this->assertIsArray($vars['tags']);
        $this->assertContains('php', $vars['tags']);
    }

    public function testRecursiveRequestQueryKeyNestedBrackets(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'recursiveRequestQueryKey');
        $method->setAccessible(true);

        $vars = [];
        $method->invokeArgs(
            $this->searchService,
            [&$vars, 'filter[status][type]', 'filter', 'active']
        );

        $this->assertIsArray($vars['filter']);
        $this->assertIsArray($vars['filter']['status']);
        $this->assertEquals('active', $vars['filter']['status']['type']);
    }

    public function testRecursiveRequestQueryKeyMultipleEmptyBrackets(): void
    {
        $method = new ReflectionMethod(SearchService::class, 'recursiveRequestQueryKey');
        $method->setAccessible(true);

        $vars = [];
        $method->invokeArgs($this->searchService, [&$vars, 'tags[]', 'tags', 'php']);
        $method->invokeArgs($this->searchService, [&$vars, 'tags[]', 'tags', 'js']);

        $this->assertIsArray($vars['tags']);
        $this->assertCount(2, $vars['tags']);
        $this->assertContains('php', $vars['tags']);
        $this->assertContains('js', $vars['tags']);
    }

    // =========================================================================
    // BASE_OBJECT constant
    // =========================================================================

    public function testBaseObjectConstant(): void
    {
        $this->assertEquals('objects', SearchService::BASE_OBJECT['database']);
        $this->assertEquals('json', SearchService::BASE_OBJECT['collection']);
    }

    // =========================================================================
    // Constructor / client property
    // =========================================================================

    public function testConstructorInitializesClient(): void
    {
        $this->assertInstanceOf(Client::class, $this->searchService->client);
    }
}
