<?php

declare(strict_types=1);

namespace Unit\Service;

use Elastic\Elasticsearch\Client;
use OCA\OpenCatalogi\Service\ElasticSearchClientAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Mockable interface mirroring the Elasticsearch Client methods used by the adapter.
 *
 * The real Elastic\Elasticsearch\Client is declared final and cannot be mocked
 * by PHPUnit. This interface allows us to create a mock with the same method
 * signatures without extending the final class.
 */
interface MockableElasticClientForAdapter
{
    public function search(array $params = []);
    public function index(array $params = []);
    public function get(array $params = []);
    public function delete(array $params = []);
    public function update(array $params = []);
}

/**
 * Unit tests for ElasticSearchClientAdapter.
 *
 * Each method in the adapter simply delegates to the underlying Elasticsearch Client.
 * Tests verify correct delegation, parameter forwarding, return value pass-through,
 * and exception propagation.
 */
class ElasticSearchClientAdapterTest extends TestCase
{
    /**
     * Mock Elasticsearch client (uses MockableElasticClientForAdapter since Client is final).
     *
     * @var \PHPUnit\Framework\MockObject\MockObject|MockableElasticClientForAdapter
     */
    private $mockClient;

    /**
     * The adapter under test.
     *
     * @var ElasticSearchClientAdapter
     */
    private ElasticSearchClientAdapter $adapter;

    /**
     * Set up test fixtures.
     *
     * Uses reflection to bypass the Client type hint in the adapter constructor,
     * injecting our mockable interface mock instead of the final Client class.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockClient = $this->createMock(MockableElasticClientForAdapter::class);

        // ElasticSearchClientAdapter constructor type-hints Client (which is final),
        // so we use reflection to create the adapter without calling the constructor
        // and inject our mock directly into the private $client property.
        $reflection = new \ReflectionClass(ElasticSearchClientAdapter::class);
        $this->adapter = $reflection->newInstanceWithoutConstructor();
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->adapter, $this->mockClient);
    }

    // ─── search ──────────────────────────────────────────────────

    /**
     * Test search delegates to client search with correct params.
     *
     * @return void
     */
    public function testSearchDelegatesToClient(): void
    {
        $params = [
            'index' => 'test_index',
            'body'  => ['query' => ['match_all' => new \stdClass()]],
        ];

        $expectedResponse = [
            'hits' => [
                'total' => ['value' => 5],
                'hits'  => [['_id' => '1', '_source' => ['title' => 'Test']]],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->with($params)
            ->willReturn($expectedResponse);

        $result = $this->adapter->search($params);

        $this->assertSame($expectedResponse, $result);
    }

    /**
     * Test search with empty params.
     *
     * @return void
     */
    public function testSearchWithEmptyParams(): void
    {
        $this->mockClient->expects($this->once())
            ->method('search')
            ->with([])
            ->willReturn(['hits' => ['total' => ['value' => 0], 'hits' => []]]);

        $result = $this->adapter->search([]);

        $this->assertSame(0, $result['hits']['total']['value']);
    }

    /**
     * Test search propagates exceptions from the client.
     *
     * @return void
     */
    public function testSearchPropagatesException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('search')
            ->willThrowException(new \RuntimeException('Search failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Search failed');

        $this->adapter->search(['index' => 'test']);
    }

    /**
     * Test search with complex query body.
     *
     * @return void
     */
    public function testSearchWithComplexQuery(): void
    {
        $params = [
            'index' => 'publications',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must'   => [['match' => ['status' => 'published']]],
                        'filter' => [['range' => ['date' => ['gte' => '2024-01-01']]]],
                    ],
                ],
                'size' => 10,
                'from' => 20,
                'sort' => [['date' => 'desc']],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->with($params)
            ->willReturn(['hits' => ['total' => ['value' => 100], 'hits' => []]]);

        $result = $this->adapter->search($params);

        $this->assertSame(100, $result['hits']['total']['value']);
    }

    /**
     * Test search returns result with aggregations.
     *
     * @return void
     */
    public function testSearchWithAggregations(): void
    {
        $params = [
            'index' => 'test_index',
            'body'  => [
                'query' => ['match_all' => new \stdClass()],
                'aggs'  => ['status' => ['terms' => ['field' => 'status']]],
            ],
        ];

        $response = [
            'hits'         => ['total' => ['value' => 10], 'hits' => []],
            'aggregations' => [
                'status' => [
                    'buckets' => [
                        ['key' => 'published', 'doc_count' => 7],
                        ['key' => 'draft', 'doc_count' => 3],
                    ],
                ],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('search')
            ->with($params)
            ->willReturn($response);

        $result = $this->adapter->search($params);

        $this->assertArrayHasKey('aggregations', $result);
        $this->assertCount(2, $result['aggregations']['status']['buckets']);
    }

    // ─── index ───────────────────────────────────────────────────

    /**
     * Test index delegates to client index with correct params.
     *
     * @return void
     */
    public function testIndexDelegatesToClient(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
            'body'  => ['title' => 'New Document', 'status' => 'draft'],
        ];

        $expectedResponse = [
            '_index'   => 'test_index',
            '_id'      => 'doc-1',
            'result'   => 'created',
            '_version' => 1,
        ];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($params)
            ->willReturn($expectedResponse);

        $result = $this->adapter->index($params);

        $this->assertSame($expectedResponse, $result);
    }

    /**
     * Test index propagates exceptions from the client.
     *
     * @return void
     */
    public function testIndexPropagatesException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('index')
            ->willThrowException(new \RuntimeException('Index failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Index failed');

        $this->adapter->index(['index' => 'test', 'id' => '1', 'body' => []]);
    }

    /**
     * Test index without explicit ID (auto-generated).
     *
     * @return void
     */
    public function testIndexWithoutId(): void
    {
        $params = [
            'index' => 'test_index',
            'body'  => ['title' => 'Auto ID'],
        ];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($params)
            ->willReturn(['_id' => 'auto-generated', 'result' => 'created']);

        $result = $this->adapter->index($params);

        $this->assertSame('created', $result['result']);
        $this->assertSame('auto-generated', $result['_id']);
    }

    /**
     * Test index with update (re-index existing document).
     *
     * @return void
     */
    public function testIndexReturnsUpdatedResult(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'existing-doc',
            'body'  => ['title' => 'Updated'],
        ];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($params)
            ->willReturn([
                '_id'      => 'existing-doc',
                'result'   => 'updated',
                '_version' => 2,
            ]);

        $result = $this->adapter->index($params);

        $this->assertSame('updated', $result['result']);
        $this->assertSame(2, $result['_version']);
    }

    /**
     * Test index with large document body.
     *
     * @return void
     */
    public function testIndexWithLargeBody(): void
    {
        $body = [];
        for ($i = 0; $i < 100; $i++) {
            $body["field_$i"] = str_repeat('x', 100);
        }

        $params = ['index' => 'test_index', 'id' => 'big-doc', 'body' => $body];

        $this->mockClient->expects($this->once())
            ->method('index')
            ->with($params)
            ->willReturn(['result' => 'created']);

        $result = $this->adapter->index($params);

        $this->assertSame('created', $result['result']);
    }

    // ─── get ─────────────────────────────────────────────────────

    /**
     * Test get delegates to client get with correct params.
     *
     * @return void
     */
    public function testGetDelegatesToClient(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
        ];

        $expectedResponse = [
            '_index'  => 'test_index',
            '_id'     => 'doc-1',
            'found'   => true,
            '_source' => ['title' => 'Found Document'],
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with($params)
            ->willReturn($expectedResponse);

        $result = $this->adapter->get($params);

        $this->assertSame($expectedResponse, $result);
        $this->assertTrue($result['found']);
    }

    /**
     * Test get propagates exceptions (e.g., document not found).
     *
     * @return void
     */
    public function testGetPropagatesException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Document not found'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Document not found');

        $this->adapter->get(['index' => 'test', 'id' => 'nonexistent']);
    }

    /**
     * Test get returns _source data correctly.
     *
     * @return void
     */
    public function testGetReturnsSourceData(): void
    {
        $params = ['index' => 'idx', 'id' => 'doc-x'];
        $source = ['field1' => 'val1', 'field2' => 42, 'nested' => ['a' => 'b']];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with($params)
            ->willReturn(['_source' => $source, 'found' => true]);

        $result = $this->adapter->get($params);

        $this->assertSame($source, $result['_source']);
    }

    /**
     * Test get with additional params like _source_includes.
     *
     * @return void
     */
    public function testGetWithSourceIncludes(): void
    {
        $params = [
            'index'            => 'test_index',
            'id'               => 'doc-1',
            '_source_includes' => ['title', 'status'],
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with($params)
            ->willReturn([
                '_source' => ['title' => 'Partial', 'status' => 'active'],
                'found'   => true,
            ]);

        $result = $this->adapter->get($params);

        $this->assertArrayHasKey('title', $result['_source']);
        $this->assertArrayHasKey('status', $result['_source']);
    }

    /**
     * Test get passes through version information.
     *
     * @return void
     */
    public function testGetReturnsVersion(): void
    {
        $params = ['index' => 'test_index', 'id' => 'doc-1'];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with($params)
            ->willReturn([
                '_id'      => 'doc-1',
                '_version' => 7,
                'found'    => true,
                '_source'  => [],
            ]);

        $result = $this->adapter->get($params);

        $this->assertSame(7, $result['_version']);
    }

    // ─── update ──────────────────────────────────────────────────

    /**
     * Test update delegates to client update with correct params.
     *
     * @return void
     */
    public function testUpdateDelegatesToClient(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
            'body'  => ['doc' => ['title' => 'Updated Title']],
        ];

        $expectedResponse = [
            '_index'   => 'test_index',
            '_id'      => 'doc-1',
            'result'   => 'updated',
            '_version' => 3,
        ];

        $this->mockClient->expects($this->once())
            ->method('update')
            ->with($params)
            ->willReturn($expectedResponse);

        $result = $this->adapter->update($params);

        $this->assertSame($expectedResponse, $result);
        $this->assertSame('updated', $result['result']);
    }

    /**
     * Test update propagates exceptions from the client.
     *
     * @return void
     */
    public function testUpdatePropagatesException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('update')
            ->willThrowException(new \RuntimeException('Update failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Update failed');

        $this->adapter->update(['index' => 'test', 'id' => '1', 'body' => []]);
    }

    /**
     * Test update with script-based update.
     *
     * @return void
     */
    public function testUpdateWithScript(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
            'body'  => [
                'script' => [
                    'source' => 'ctx._source.counter += params.count',
                    'params' => ['count' => 1],
                ],
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('update')
            ->with($params)
            ->willReturn(['result' => 'updated']);

        $result = $this->adapter->update($params);

        $this->assertSame('updated', $result['result']);
    }

    /**
     * Test update returns noop when nothing changed.
     *
     * @return void
     */
    public function testUpdateReturnsNoop(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
            'body'  => ['doc' => ['title' => 'Same Title']],
        ];

        $this->mockClient->expects($this->once())
            ->method('update')
            ->with($params)
            ->willReturn(['result' => 'noop', '_version' => 5]);

        $result = $this->adapter->update($params);

        $this->assertSame('noop', $result['result']);
    }

    /**
     * Test update with upsert creates document if not exists.
     *
     * @return void
     */
    public function testUpdateWithUpsert(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'maybe-new',
            'body'  => [
                'doc'           => ['title' => 'Upserted'],
                'doc_as_upsert' => true,
            ],
        ];

        $this->mockClient->expects($this->once())
            ->method('update')
            ->with($params)
            ->willReturn(['result' => 'created', '_version' => 1]);

        $result = $this->adapter->update($params);

        $this->assertSame('created', $result['result']);
    }

    // ─── delete ──────────────────────────────────────────────────

    /**
     * Test delete delegates to client delete with correct params.
     *
     * @return void
     */
    public function testDeleteDelegatesToClient(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'doc-1',
        ];

        $expectedResponse = [
            '_index'   => 'test_index',
            '_id'      => 'doc-1',
            'result'   => 'deleted',
            '_version' => 4,
        ];

        $this->mockClient->expects($this->once())
            ->method('delete')
            ->with($params)
            ->willReturn($expectedResponse);

        $result = $this->adapter->delete($params);

        $this->assertSame($expectedResponse, $result);
        $this->assertSame('deleted', $result['result']);
    }

    /**
     * Test delete propagates exceptions (e.g., document not found).
     *
     * @return void
     */
    public function testDeletePropagatesException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('delete')
            ->willThrowException(new \RuntimeException('Delete failed: not found'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delete failed: not found');

        $this->adapter->delete(['index' => 'test', 'id' => 'nonexistent']);
    }

    /**
     * Test delete with routing parameter.
     *
     * @return void
     */
    public function testDeleteWithRouting(): void
    {
        $params = [
            'index'   => 'test_index',
            'id'      => 'doc-1',
            'routing' => 'custom-route',
        ];

        $this->mockClient->expects($this->once())
            ->method('delete')
            ->with($params)
            ->willReturn(['result' => 'deleted']);

        $result = $this->adapter->delete($params);

        $this->assertSame('deleted', $result['result']);
    }

    /**
     * Test delete returns not_found result for already-deleted document.
     *
     * @return void
     */
    public function testDeleteReturnsNotFound(): void
    {
        $params = [
            'index' => 'test_index',
            'id'    => 'already-gone',
        ];

        $this->mockClient->expects($this->once())
            ->method('delete')
            ->with($params)
            ->willReturn(['result' => 'not_found']);

        $result = $this->adapter->delete($params);

        $this->assertSame('not_found', $result['result']);
    }

    // ─── constructor ─────────────────────────────────────────────

    /**
     * Test that the adapter stores the client and it is accessible via reflection.
     *
     * @return void
     */
    public function testConstructorStoresClient(): void
    {
        $reflection = new \ReflectionClass(ElasticSearchClientAdapter::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);

        $storedClient = $property->getValue($this->adapter);

        $this->assertSame($this->mockClient, $storedClient);
    }

    /**
     * Test that separate adapter instances use separate clients.
     *
     * @return void
     */
    public function testSeparateAdaptersUseSeparateClients(): void
    {
        $otherClient = $this->createMock(MockableElasticClientForAdapter::class);

        $reflection = new \ReflectionClass(ElasticSearchClientAdapter::class);
        $otherAdapter = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($otherAdapter, $otherClient);

        $this->assertSame($this->mockClient, $property->getValue($this->adapter));
        $this->assertSame($otherClient, $property->getValue($otherAdapter));
        $this->assertNotSame(
            $property->getValue($this->adapter),
            $property->getValue($otherAdapter)
        );
    }
}
