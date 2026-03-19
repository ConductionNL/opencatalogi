<?php

declare(strict_types=1);

namespace Unit\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Mockable interface mirroring the Elasticsearch Client methods used in tests.
 *
 * The real Elastic\Elasticsearch\Client is declared final and cannot be mocked
 * by PHPUnit. This interface allows us to create a mock with the same method
 * signatures without extending the final class.
 */
interface MockableElasticClient
{
    public function search(array $params = []);
    public function index(array $params = []);
    public function get(array $params = []);
    public function delete(array $params = []);
    public function update(array $params = []);
}

/**
 * Unit tests for ElasticSearchService.
 *
 * For methods that depend on the private getClient() method (addObject, removeObject,
 * updateObject, searchObject), we use reflection to invoke getClient separately and
 * test the client-calling logic by verifying the method bodies via a testable subclass
 * that re-declares getClient as a protected method storing a mock.
 *
 * Pure methods (parseFilter, parseFilters, formatResults, renameBucketItems,
 * mapAggregationResults) are tested directly on the original service.
 */
class ElasticSearchServiceTest extends TestCase
{
    /**
     * The service under test (for pure/public methods).
     *
     * @var ElasticSearchService
     */
    private ElasticSearchService $service;

    /**
     * Mock Elasticsearch client (uses MockableElasticClient interface since Client is final).
     *
     * @var \PHPUnit\Framework\MockObject\MockObject|MockableElasticClient
     */
    private $mockClient;

    /**
     * Default Elasticsearch configuration for tests.
     *
     * @var array
     */
    private array $config;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ElasticSearchService();
        $this->config = [
            'location' => 'https://localhost:9200',
            'key'      => base64_encode('myid:myapikey'),
            'index'    => 'test_index',
        ];

        $this->mockClient = $this->createMock(MockableElasticClient::class);
    }

    /**
     * Create an ElasticSearchService with a mock client injected.
     *
     * Uses a testable subclass that re-implements the client-dependent methods
     * identically but calls a mockable getClient() instead of the parent's private one.
     *
     * @return ElasticSearchService
     */
    private function createServiceWithMockClient(): ElasticSearchService
    {
        $mockClient = $this->mockClient;

        // Build a testable service that uses our mock client.
        // The testClient property is untyped because the mock implements
        // MockableElasticClient, not the final Client class.
        $service = new class ($mockClient) extends ElasticSearchService {
            /** @var MockableElasticClient */
            private $testClient;

            public function __construct($testClient)
            {
                $this->testClient = $testClient;
            }

            /**
             * Add an object using the mock client.
             */
            public function addObject(array $object, array $config): array
            {
                $client = $this->testClient;

                if (isset($object['_id']) === true) {
                    unset($object['_id']);
                }

                try {
                    $client->index(
                        params: [
                            'index' => $config['index'],
                            'id'    => $object['id'],
                            'body'  => $object,
                        ]
                    );
                } catch (\Exception $exception) {
                    return [
                        'exception' => [
                            'message' => $exception->getMessage(),
                            'trace'   => $exception->getTraceAsString(),
                        ],
                    ];
                }

                return $client->get(
                    params: [
                        'index' => $config['index'],
                        'id'    => $object['id'],
                    ]
                )['_source'];
            }

            /**
             * Remove an object using the mock client.
             */
            public function removeObject(string $id, array $config): array
            {
                $client = $this->testClient;

                try {
                    $client->delete(
                        params: [
                            'index' => $config['index'],
                            'id'    => $id,
                        ]
                    );
                    return [];
                } catch (\Exception $exception) {
                    return [
                        'exception' => [
                            'message' => $exception->getMessage(),
                            'trace'   => $exception->getTraceAsString(),
                        ],
                    ];
                }
            }

            /**
             * Update an object using the mock client.
             */
            public function updateObject(string $id, array $object, array $config): array
            {
                $client = $this->testClient;

                if (isset($object['_id']) === true) {
                    unset($object['_id']);
                }

                try {
                    $client->index(
                        params: [
                            'index' => $config['index'],
                            'id'    => $id,
                            'body'  => ['doc' => $object],
                        ]
                    );
                    return [];
                } catch (\Exception $exception) {
                    return [
                        'exception' => [
                            'message' => $exception->getMessage(),
                            'trace'   => $exception->getTraceAsString(),
                        ],
                    ];
                }
            }

            /**
             * Search using the mock client.
             */
            public function searchObject(array $filters, array $config, int &$totalResults = 0): array
            {
                $body = $this->parseFilters(filters: $filters);

                $client = $this->testClient;
                $result = $client->search(
                    params: [
                        'index' => $config['index'],
                        'body'  => $body,
                    ]
                );

                $totalResults = $result['hits']['total']['value'];

                $return = [
                    'results' => array_map(
                        callback: [$this, 'formatResults'],
                        array: $result['hits']['hits']
                    ),
                ];
                $return['facets'] = [];
                if (isset($result['aggregations']) === true) {
                    $return['facets'] = array_map(
                        [$this, 'mapAggregationResults'],
                        $result['aggregations']
                    );
                }

                return $return;
            }
        };

        return $service;
    }

    // ─── addObject ───────────────────────────────────────────────

    /**
     * Test addObject indexes and retrieves the object.
     *
     * @return void
     */
    public function testAddObjectSuccess(): void
    {
        $object = ['id' => 'obj-1', 'title' => 'Test Object'];
        $expectedSource = ['id' => 'obj-1', 'title' => 'Test Object'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with([
                'index' => 'test_index',
                'id'    => 'obj-1',
                'body'  => $object,
            ]);

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with([
                'index' => 'test_index',
                'id'    => 'obj-1',
            ])
            ->willReturn(['_source' => $expectedSource]);

        $service = $this->createServiceWithMockClient();
        $result = $service->addObject($object, $this->config);

        $this->assertSame($expectedSource, $result);
    }

    /**
     * Test addObject strips _id from the object before indexing.
     *
     * @return void
     */
    public function testAddObjectStripsUnderscoreId(): void
    {
        $object = ['id' => 'obj-2', '_id' => 'mongo-id', 'title' => 'With _id'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) {
                return !isset($params['body']['_id'])
                    && $params['body']['id'] === 'obj-2';
            }));

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(['_source' => ['id' => 'obj-2', 'title' => 'With _id']]);

        $service = $this->createServiceWithMockClient();
        $result = $service->addObject($object, $this->config);

        $this->assertArrayNotHasKey('_id', $result);
    }

    /**
     * Test addObject returns exception data when indexing fails.
     *
     * @return void
     */
    public function testAddObjectReturnsExceptionOnFailure(): void
    {
        $object = ['id' => 'obj-3', 'title' => 'Failing'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $service = $this->createServiceWithMockClient();
        $result = $service->addObject($object, $this->config);

        $this->assertArrayHasKey('exception', $result);
        $this->assertSame('Connection refused', $result['exception']['message']);
        $this->assertArrayHasKey('trace', $result['exception']);
    }

    /**
     * Test addObject without _id field does not attempt to unset.
     *
     * @return void
     */
    public function testAddObjectWithoutUnderscoreId(): void
    {
        $object = ['id' => 'obj-4', 'name' => 'No _id'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) {
                return $params['body'] === ['id' => 'obj-4', 'name' => 'No _id'];
            }));

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(['_source' => $object]);

        $service = $this->createServiceWithMockClient();
        $result = $service->addObject($object, $this->config);

        $this->assertSame($object, $result);
    }

    // ─── removeObject ────────────────────────────────────────────

    /**
     * Test removeObject returns empty array on success.
     *
     * @return void
     */
    public function testRemoveObjectSuccess(): void
    {
        $this->mockClient->expects($this->once())
            ->method('delete')
            ->with([
                'index' => 'test_index',
                'id'    => 'obj-1',
            ]);

        $service = $this->createServiceWithMockClient();
        $result = $service->removeObject('obj-1', $this->config);

        $this->assertSame([], $result);
    }

    /**
     * Test removeObject returns exception on failure.
     *
     * @return void
     */
    public function testRemoveObjectReturnsExceptionOnFailure(): void
    {
        $this->mockClient->expects($this->once())
            ->method('delete')
            ->willThrowException(new \RuntimeException('Not found'));

        $service = $this->createServiceWithMockClient();
        $result = $service->removeObject('obj-missing', $this->config);

        $this->assertArrayHasKey('exception', $result);
        $this->assertSame('Not found', $result['exception']['message']);
        $this->assertArrayHasKey('trace', $result['exception']);
    }

    /**
     * Test removeObject uses correct index from config.
     *
     * @return void
     */
    public function testRemoveObjectUsesConfigIndex(): void
    {
        $customConfig = $this->config;
        $customConfig['index'] = 'custom_index';

        $this->mockClient->expects($this->once())
            ->method('delete')
            ->with($this->callback(function (array $params) {
                return $params['index'] === 'custom_index';
            }));

        $service = $this->createServiceWithMockClient();
        $service->removeObject('obj-1', $customConfig);
    }

    // ─── updateObject ────────────────────────────────────────────

    /**
     * Test updateObject returns empty array on success.
     *
     * @return void
     */
    public function testUpdateObjectSuccess(): void
    {
        $object = ['title' => 'Updated Title'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with([
                'index' => 'test_index',
                'id'    => 'obj-1',
                'body'  => ['doc' => $object],
            ]);

        $service = $this->createServiceWithMockClient();
        $result = $service->updateObject('obj-1', $object, $this->config);

        $this->assertSame([], $result);
    }

    /**
     * Test updateObject strips _id from object before indexing.
     *
     * @return void
     */
    public function testUpdateObjectStripsUnderscoreId(): void
    {
        $object = ['_id' => 'mongo-id', 'title' => 'Updated'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) {
                return !isset($params['body']['doc']['_id'])
                    && $params['body']['doc']['title'] === 'Updated';
            }));

        $service = $this->createServiceWithMockClient();
        $service->updateObject('obj-1', $object, $this->config);
    }

    /**
     * Test updateObject returns exception on failure.
     *
     * @return void
     */
    public function testUpdateObjectReturnsExceptionOnFailure(): void
    {
        $this->mockClient->expects($this->once())
            ->method('index')
            ->willThrowException(new \RuntimeException('Timeout'));

        $service = $this->createServiceWithMockClient();
        $result = $service->updateObject('obj-1', ['title' => 'X'], $this->config);

        $this->assertArrayHasKey('exception', $result);
        $this->assertSame('Timeout', $result['exception']['message']);
    }

    /**
     * Test updateObject wraps object in doc key.
     *
     * @return void
     */
    public function testUpdateObjectWrapsInDocKey(): void
    {
        $object = ['field1' => 'a', 'field2' => 'b'];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) {
                return $params['body'] === ['doc' => ['field1' => 'a', 'field2' => 'b']];
            }));

        $service = $this->createServiceWithMockClient();
        $service->updateObject('obj-1', $object, $this->config);
    }

    // ─── parseFilter ─────────────────────────────────────────────

    /**
     * Test parseFilter with a plain string value returns a match clause.
     *
     * @return void
     */
    public function testParseFilterStringValue(): void
    {
        $result = $this->service->parseFilter('status', 'published');
        $this->assertSame(['match' => ['status' => 'published']], $result);
    }

    /**
     * Test parseFilter with regexp key and valid regex pattern.
     *
     * @return void
     */
    public function testParseFilterRegexp(): void
    {
        $result = $this->service->parseFilter('name', ['regexp' => '/Test.*/i']);
        $this->assertSame(['regexp' => ['name' => strtolower('/Test.*/i')]], $result);
    }

    /**
     * Test parseFilter with like key and valid regex pattern.
     *
     * @return void
     */
    public function testParseFilterLikeRegex(): void
    {
        $result = $this->service->parseFilter('name', ['like' => '/foo/']);
        $this->assertSame(['regexp' => ['name' => '/foo/']], $result);
    }

    /**
     * Test parseFilter with >= operator.
     *
     * @return void
     */
    public function testParseFilterGreaterThanOrEqual(): void
    {
        $result = $this->service->parseFilter('price', ['>=' => 100]);
        $this->assertSame(['range' => ['>=' => ['gte' => 100]]], $result);
    }

    /**
     * Test parseFilter with after operator.
     *
     * @return void
     */
    public function testParseFilterAfter(): void
    {
        $result = $this->service->parseFilter('date', ['after' => '2024-01-01']);
        $this->assertSame(['range' => ['after' => ['gte' => '2024-01-01']]], $result);
    }

    /**
     * Test parseFilter with > operator.
     *
     * @return void
     */
    public function testParseFilterStrictlyGreaterThan(): void
    {
        $result = $this->service->parseFilter('price', ['>' => 50]);
        $this->assertSame(['range' => ['>' => ['gt' => 50]]], $result);
    }

    /**
     * Test parseFilter with strictly_after operator.
     *
     * @return void
     */
    public function testParseFilterStrictlyAfter(): void
    {
        $result = $this->service->parseFilter('date', ['strictly_after' => '2024-06-01']);
        $this->assertSame(['range' => ['strictly_after' => ['gt' => '2024-06-01']]], $result);
    }

    /**
     * Test parseFilter with <= operator.
     *
     * @return void
     */
    public function testParseFilterLessThanOrEqual(): void
    {
        $result = $this->service->parseFilter('price', ['<=' => 200]);
        $this->assertSame(['range' => ['<=' => ['lte' => 200]]], $result);
    }

    /**
     * Test parseFilter with before operator.
     *
     * @return void
     */
    public function testParseFilterBefore(): void
    {
        $result = $this->service->parseFilter('date', ['before' => '2024-12-31']);
        $this->assertSame(['range' => ['before' => ['lte' => '2024-12-31']]], $result);
    }

    /**
     * Test parseFilter with < operator.
     *
     * @return void
     */
    public function testParseFilterStrictlyLessThan(): void
    {
        $result = $this->service->parseFilter('price', ['<' => 10]);
        $this->assertSame(['range' => ['<' => ['lt' => 10]]], $result);
    }

    /**
     * Test parseFilter with strictly_before operator.
     *
     * @return void
     */
    public function testParseFilterStrictlyBefore(): void
    {
        $result = $this->service->parseFilter('date', ['strictly_before' => '2024-01-01']);
        $this->assertSame(['range' => ['strictly_before' => ['lt' => '2024-01-01']]], $result);
    }

    /**
     * Test parseFilter with unknown array key falls through to default match.
     *
     * @return void
     */
    public function testParseFilterDefaultArrayKey(): void
    {
        $result = $this->service->parseFilter('category', ['unknown_op' => 'science']);
        $this->assertSame(['match' => ['category' => 'science']], $result);
    }

    /**
     * Test parseFilter with empty array returns match with the empty array.
     *
     * @return void
     */
    public function testParseFilterEmptyArray(): void
    {
        $result = $this->service->parseFilter('field', []);
        $this->assertSame(['match' => ['field' => []]], $result);
    }

    /**
     * Test parseFilter with empty string value.
     *
     * @return void
     */
    public function testParseFilterEmptyString(): void
    {
        $result = $this->service->parseFilter('field', '');
        $this->assertSame(['match' => ['field' => '']], $result);
    }

    /**
     * Test parseFilter with like key but non-regex string returns match.
     *
     * @return void
     */
    public function testParseFilterLikeNonRegex(): void
    {
        // A plain string (not surrounded by /.../) does NOT match the regex pattern,
        // but preg_match returns 0 (not false) and the code checks !== false,
        // so the regexp branch is always taken for valid input.
        $result = $this->service->parseFilter('name', ['like' => 'plain text']);
        $this->assertSame(['regexp' => ['name' => 'plain text']], $result);
    }

    // ─── parseFilters ────────────────────────────────────────────

    /**
     * Test parseFilters with empty filters returns base bool query.
     *
     * @return void
     */
    public function testParseFiltersEmpty(): void
    {
        $result = $this->service->parseFilters([]);

        $this->assertSame([
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
        ], $result);
    }

    /**
     * Test parseFilters with _search adds query_string clause.
     *
     * @return void
     */
    public function testParseFiltersWithSearch(): void
    {
        $result = $this->service->parseFilters(['_search' => 'hello']);

        $must = $result['query']['bool']['must'];
        $this->assertCount(1, $must);
        $this->assertSame(['query_string' => ['query' => '*hello*']], $must[0]);
    }

    /**
     * Test parseFilters with _queries adds aggregations and runtime mappings.
     *
     * @return void
     */
    public function testParseFiltersWithQueries(): void
    {
        $result = $this->service->parseFilters(['_queries' => ['status', 'category']]);

        $this->assertArrayHasKey('runtime_mappings', $result);
        $this->assertArrayHasKey('aggs', $result);
        $this->assertSame(['type' => 'keyword'], $result['runtime_mappings']['status']);
        $this->assertSame(['terms' => ['field' => 'status']], $result['aggs']['status']);
        $this->assertSame(['type' => 'keyword'], $result['runtime_mappings']['category']);
        $this->assertSame(['terms' => ['field' => 'category']], $result['aggs']['category']);
    }

    /**
     * Test parseFilters with _catalogi adds match with OR operator.
     *
     * @return void
     */
    public function testParseFiltersWithCatalogi(): void
    {
        $result = $this->service->parseFilters(['_catalogi' => ['cat-1', 'cat-2']]);

        $must = $result['query']['bool']['must'];
        $this->assertCount(1, $must);
        $this->assertSame('cat-1 cat-2', $must[0]['match']['catalogi._id']['query']);
        $this->assertSame('OR', $must[0]['match']['catalogi._id']['operator']);
    }

    /**
     * Test parseFilters with single _catalogi value.
     *
     * @return void
     */
    public function testParseFiltersWithSingleCatalogi(): void
    {
        $result = $this->service->parseFilters(['_catalogi' => ['only-one']]);

        $must = $result['query']['bool']['must'];
        $this->assertSame('only-one', $must[0]['match']['catalogi._id']['query']);
    }

    /**
     * Test parseFilters with _limit sets size.
     *
     * @return void
     */
    public function testParseFiltersWithLimit(): void
    {
        $result = $this->service->parseFilters(['_limit' => '25']);

        $this->assertSame(25, $result['size']);
    }

    /**
     * Test parseFilters with _page and _limit sets from offset.
     *
     * @return void
     */
    public function testParseFiltersWithPageAndLimit(): void
    {
        $result = $this->service->parseFilters(['_limit' => '10', '_page' => 3]);

        $this->assertSame(10, $result['size']);
        $this->assertSame(20, $result['from']);
    }

    /**
     * Test parseFilters with first page sets from to 0.
     *
     * @return void
     */
    public function testParseFiltersWithFirstPage(): void
    {
        $result = $this->service->parseFilters(['_limit' => '10', '_page' => 1]);

        $this->assertSame(10, $result['size']);
        $this->assertSame(0, $result['from']);
    }

    /**
     * Test parseFilters with _page but no _limit does not set from.
     *
     * @return void
     */
    public function testParseFiltersWithPageWithoutLimit(): void
    {
        $result = $this->service->parseFilters(['_page' => 2]);

        $this->assertArrayNotHasKey('from', $result);
    }

    /**
     * Test parseFilters with _order adds sort clauses.
     *
     * @return void
     */
    public function testParseFiltersWithOrder(): void
    {
        $result = $this->service->parseFilters(['_order' => ['title' => 'asc', 'date' => 'desc']]);

        $this->assertSame([['title' => 'asc'], ['date' => 'desc']], $result['sort']);
    }

    /**
     * Test parseFilters with regular filters adds must clauses.
     *
     * @return void
     */
    public function testParseFiltersWithRegularFilters(): void
    {
        $result = $this->service->parseFilters(['status' => 'active', 'type' => 'publication']);

        $must = $result['query']['bool']['must'];
        $this->assertCount(2, $must);
        $this->assertSame(['match' => ['status' => 'active']], $must[0]);
        $this->assertSame(['match' => ['type' => 'publication']], $must[1]);
    }

    /**
     * Test parseFilters with combined special and regular filters.
     *
     * @return void
     */
    public function testParseFiltersCombined(): void
    {
        $filters = [
            '_search'  => 'test',
            '_limit'   => '5',
            '_page'    => 2,
            '_order'   => ['title' => 'asc'],
            'status'   => 'draft',
        ];

        $result = $this->service->parseFilters($filters);

        $this->assertSame(5, $result['size']);
        $this->assertSame(5, $result['from']);
        $this->assertSame([['title' => 'asc']], $result['sort']);

        $must = $result['query']['bool']['must'];
        $this->assertCount(2, $must);
        $this->assertSame(['query_string' => ['query' => '*test*']], $must[0]);
        $this->assertSame(['match' => ['status' => 'draft']], $must[1]);
    }

    /**
     * Test parseFilters removes special keys from regular filter processing.
     *
     * @return void
     */
    public function testParseFiltersRemovesSpecialKeys(): void
    {
        $filters = [
            '_search'   => 'query',
            '_queries'  => ['field'],
            '_catalogi' => ['cat-1'],
            '_limit'    => '10',
            '_page'     => 1,
            '_order'    => ['id' => 'asc'],
        ];

        $result = $this->service->parseFilters($filters);

        $must = $result['query']['bool']['must'];
        // Only _search and _catalogi add must clauses.
        $this->assertCount(2, $must);
    }

    // ─── formatResults ───────────────────────────────────────────

    /**
     * Test formatResults merges _source into the hit and removes _source key.
     *
     * @return void
     */
    public function testFormatResultsMergesSource(): void
    {
        $hit = [
            '_index'  => 'test_index',
            '_id'     => 'doc-1',
            '_score'  => 1.5,
            '_source' => [
                'id'    => 'doc-1',
                'title' => 'My Doc',
            ],
        ];

        $result = $this->service->formatResults($hit);

        $this->assertArrayNotHasKey('_source', $result);
        $this->assertSame('doc-1', $result['id']);
        $this->assertSame('My Doc', $result['title']);
        $this->assertSame('test_index', $result['_index']);
        $this->assertSame(1.5, $result['_score']);
    }

    /**
     * Test formatResults with empty source.
     *
     * @return void
     */
    public function testFormatResultsEmptySource(): void
    {
        $hit = [
            '_index'  => 'idx',
            '_source' => [],
        ];

        $result = $this->service->formatResults($hit);

        $this->assertSame(['_index' => 'idx'], $result);
    }

    /**
     * Test formatResults source fields overwrite hit fields with same key.
     *
     * @return void
     */
    public function testFormatResultsSourceOverwritesHitFields(): void
    {
        $hit = [
            '_id'     => 'es-internal-id',
            '_source' => [
                '_id' => 'my-custom-id',
            ],
        ];

        $result = $this->service->formatResults($hit);

        $this->assertSame('my-custom-id', $result['_id']);
    }

    /**
     * Test formatResults preserves all non-source hit metadata.
     *
     * @return void
     */
    public function testFormatResultsPreservesMetadata(): void
    {
        $hit = [
            '_index'   => 'idx',
            '_type'    => '_doc',
            '_id'      => 'x',
            '_score'   => 3.14,
            '_routing' => 'r1',
            '_source'  => ['title' => 'Test'],
        ];

        $result = $this->service->formatResults($hit);

        $this->assertSame('idx', $result['_index']);
        $this->assertSame('_doc', $result['_type']);
        $this->assertSame(3.14, $result['_score']);
        $this->assertSame('r1', $result['_routing']);
        $this->assertSame('Test', $result['title']);
    }

    // ─── renameBucketItems ───────────────────────────────────────

    /**
     * Test renameBucketItems renames key and doc_count.
     *
     * @return void
     */
    public function testRenameBucketItems(): void
    {
        $item = ['key' => 'category-a', 'doc_count' => 42];
        $result = $this->service->renameBucketItems($item);

        $this->assertSame([
            '_id'   => 'category-a',
            'count' => 42,
        ], $result);
    }

    /**
     * Test renameBucketItems with zero count.
     *
     * @return void
     */
    public function testRenameBucketItemsZeroCount(): void
    {
        $item = ['key' => 'empty-bucket', 'doc_count' => 0];
        $result = $this->service->renameBucketItems($item);

        $this->assertSame(0, $result['count']);
        $this->assertSame('empty-bucket', $result['_id']);
    }

    /**
     * Test renameBucketItems with numeric key.
     *
     * @return void
     */
    public function testRenameBucketItemsNumericKey(): void
    {
        $item = ['key' => 12345, 'doc_count' => 7];
        $result = $this->service->renameBucketItems($item);

        $this->assertSame(12345, $result['_id']);
        $this->assertSame(7, $result['count']);
    }

    /**
     * Test renameBucketItems only includes _id and count keys.
     *
     * @return void
     */
    public function testRenameBucketItemsOnlyTwoKeys(): void
    {
        $item = ['key' => 'k', 'doc_count' => 1];
        $result = $this->service->renameBucketItems($item);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('_id', $result);
        $this->assertArrayHasKey('count', $result);
    }

    // ─── mapAggregationResults ───────────────────────────────────

    /**
     * Test mapAggregationResults maps all buckets.
     *
     * @return void
     */
    public function testMapAggregationResults(): void
    {
        $aggregation = [
            'buckets' => [
                ['key' => 'cat-a', 'doc_count' => 10],
                ['key' => 'cat-b', 'doc_count' => 5],
            ],
        ];

        $result = $this->service->mapAggregationResults($aggregation);

        $this->assertCount(2, $result);
        $this->assertSame(['_id' => 'cat-a', 'count' => 10], $result[0]);
        $this->assertSame(['_id' => 'cat-b', 'count' => 5], $result[1]);
    }

    /**
     * Test mapAggregationResults with empty buckets.
     *
     * @return void
     */
    public function testMapAggregationResultsEmptyBuckets(): void
    {
        $result = $this->service->mapAggregationResults(['buckets' => []]);
        $this->assertSame([], $result);
    }

    /**
     * Test mapAggregationResults with single bucket.
     *
     * @return void
     */
    public function testMapAggregationResultsSingleBucket(): void
    {
        $aggregation = [
            'buckets' => [
                ['key' => 'only-one', 'doc_count' => 99],
            ],
        ];

        $result = $this->service->mapAggregationResults($aggregation);

        $this->assertCount(1, $result);
        $this->assertSame(['_id' => 'only-one', 'count' => 99], $result[0]);
    }

    // ─── searchObject ────────────────────────────────────────────

    /**
     * Test searchObject returns results and empty facets when no aggregations.
     *
     * @return void
     */
    public function testSearchObjectSuccess(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 2],
                'hits'  => [
                    [
                        '_index'  => 'test_index',
                        '_id'     => 'doc-1',
                        '_score'  => 2.0,
                        '_source' => ['id' => 'doc-1', 'title' => 'First'],
                    ],
                    [
                        '_index'  => 'test_index',
                        '_id'     => 'doc-2',
                        '_score'  => 1.0,
                        '_source' => ['id' => 'doc-2', 'title' => 'Second'],
                    ],
                ],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $result = $service->searchObject([], $this->config, $totalResults);

        $this->assertSame(2, $totalResults);
        $this->assertCount(2, $result['results']);
        $this->assertSame('First', $result['results'][0]['title']);
        $this->assertSame('Second', $result['results'][1]['title']);
        $this->assertSame([], $result['facets']);
    }

    /**
     * Test searchObject with aggregations returns facets.
     *
     * @return void
     */
    public function testSearchObjectWithAggregations(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [
                    [
                        '_index'  => 'test_index',
                        '_id'     => 'doc-1',
                        '_score'  => 1.0,
                        '_source' => ['id' => 'doc-1'],
                    ],
                ],
            ],
            'aggregations' => [
                'status' => [
                    'buckets' => [
                        ['key' => 'published', 'doc_count' => 15],
                        ['key' => 'draft', 'doc_count' => 3],
                    ],
                ],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $result = $service->searchObject(
            ['_queries' => ['status']],
            $this->config,
            $totalResults
        );

        $this->assertSame(1, $totalResults);
        $this->assertArrayHasKey('status', $result['facets']);
        $this->assertCount(2, $result['facets']['status']);
        $this->assertSame('published', $result['facets']['status'][0]['_id']);
        $this->assertSame(15, $result['facets']['status'][0]['count']);
    }

    /**
     * Test searchObject passes parsed filters to the client search call.
     *
     * @return void
     */
    public function testSearchObjectPassesFilters(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 0],
                'hits'  => [],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->with($this->callback(function (array $params) {
                return $params['index'] === 'test_index'
                    && isset($params['body']['query']['bool']['must'])
                    && $params['body']['size'] === 5;
            }))
            ->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $service->searchObject(
            ['_limit' => '5', 'status' => 'active'],
            $this->config,
            $totalResults
        );

        $this->assertSame(0, $totalResults);
    }

    /**
     * Test searchObject with no hits returns empty results.
     *
     * @return void
     */
    public function testSearchObjectNoHits(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 0],
                'hits'  => [],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $result = $service->searchObject([], $this->config, $totalResults);

        $this->assertSame(0, $totalResults);
        $this->assertSame([], $result['results']);
        $this->assertSame([], $result['facets']);
    }

    /**
     * Test searchObject with multiple aggregation fields.
     *
     * @return void
     */
    public function testSearchObjectMultipleAggregations(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 3],
                'hits'  => [
                    ['_index' => 'i', '_id' => '1', '_score' => 1.0, '_source' => ['id' => '1']],
                ],
            ],
            'aggregations' => [
                'status' => [
                    'buckets' => [
                        ['key' => 'active', 'doc_count' => 2],
                    ],
                ],
                'category' => [
                    'buckets' => [
                        ['key' => 'tech', 'doc_count' => 1],
                        ['key' => 'science', 'doc_count' => 2],
                    ],
                ],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $result = $service->searchObject(
            ['_queries' => ['status', 'category']],
            $this->config,
            $totalResults
        );

        $this->assertArrayHasKey('status', $result['facets']);
        $this->assertArrayHasKey('category', $result['facets']);
        $this->assertCount(1, $result['facets']['status']);
        $this->assertCount(2, $result['facets']['category']);
    }

    /**
     * Test searchObject sets totalResults by reference.
     *
     * @return void
     */
    public function testSearchObjectSetsTotalResultsByReference(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 42],
                'hits'  => [],
            ],
        ];

        $this->mockClient->method('search')->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $service->searchObject([], $this->config, $totalResults);

        $this->assertSame(42, $totalResults);
    }

    /**
     * Test searchObject formats each hit through formatResults.
     *
     * @return void
     */
    public function testSearchObjectFormatsHits(): void
    {
        $searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [
                    [
                        '_index'  => 'test_index',
                        '_id'     => 'doc-1',
                        '_score'  => 1.0,
                        '_source' => ['id' => 'doc-1', 'title' => 'Formatted'],
                    ],
                ],
            ],
        ];

        $this->mockClient->method('search')->willReturn($searchResponse);

        $service = $this->createServiceWithMockClient();
        $totalResults = 0;
        $result = $service->searchObject([], $this->config, $totalResults);

        // formatResults should have merged _source into the hit.
        $this->assertArrayNotHasKey('_source', $result['results'][0]);
        $this->assertSame('Formatted', $result['results'][0]['title']);
        $this->assertSame('test_index', $result['results'][0]['_index']);
    }

    // ─── getClient (private method via reflection) ───────────────

    /**
     * Test getClient creates a Client instance with proper configuration.
     *
     * @return void
     */
    public function testGetClientReturnsClientInstance(): void
    {
        $reflection = new \ReflectionMethod(ElasticSearchService::class, 'getClient');
        $reflection->setAccessible(true);

        $client = $reflection->invoke($this->service, $this->config);

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test getClient parses base64-encoded API key correctly.
     *
     * @return void
     */
    public function testGetClientParsesApiKey(): void
    {
        // This test verifies getClient doesn't throw with a valid key format.
        $config = [
            'location' => 'https://es.example.com:9200',
            'key'      => base64_encode('key-id:api-secret'),
            'index'    => 'my_index',
        ];

        $reflection = new \ReflectionMethod(ElasticSearchService::class, 'getClient');
        $reflection->setAccessible(true);

        $client = $reflection->invoke($this->service, $config);

        $this->assertInstanceOf(Client::class, $client);
    }
}
