<?php

namespace OCA\OpenCatalogi\Tests\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use OCA\OpenCatalogi\Service\SearchService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SearchServiceTest extends TestCase
{
    /** @var MockObject&ElasticSearchService */
    private $elasticSearchService;

    /** @var MockObject&DirectoryService */
    private $directoryService;

    /** @var MockObject&IURLGenerator */
    private $urlGenerator;

    /** @var SearchService */
    private $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->elasticSearchService = $this->createMock(ElasticSearchService::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        $this->searchService = new SearchService(
            $this->elasticSearchService,
            $this->directoryService,
            $this->urlGenerator
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(SearchService::class, $this->searchService);
    }

    public function testSortResultArray(): void
    {
        $a = ['_score' => 1];
        $b = ['_score' => 2];

        $this->assertEquals(-1, $this->searchService->sortResultArray($a, $b));
        $this->assertEquals(1, $this->searchService->sortResultArray($b, $a));
        $this->assertEquals(0, $this->searchService->sortResultArray($a, $a));
    }

    public function testMergeFacets(): void
    {
        $existingAggregation = [
            ['_id' => 'category1', 'count' => 10],
            ['_id' => 'category2', 'count' => 5],
        ];
        $newAggregation = [
            ['_id' => 'category1', 'count' => 3],
            ['_id' => 'category3', 'count' => 7],
        ];

        $expected = [
            ['_id' => 'category1', 'count' => 13],
            ['_id' => 'category2', 'count' => 5],
            ['_id' => 'category3', 'count' => 7],
        ];

        $result = $this->searchService->mergeFacets($existingAggregation, $newAggregation);

        $this->assertEquals($expected, $result);
    }

    public function testMergeFacetsWithEmptyArrays(): void
    {
        $result = $this->searchService->mergeFacets([], []);
        $this->assertEquals([], $result);
    }

    public function testMergeFacetsWithOneEmptyArray(): void
    {
        $existing = [['_id' => 'cat1', 'count' => 5]];

        $result = $this->searchService->mergeFacets($existing, []);
        $this->assertEquals($existing, $result);
    }

    public function testUnsetSpecialQueryParams(): void
    {
        $filters = [
            '_search' => 'test',
            '_order' => 'asc',
            'category' => 'news',
            '_limit' => 10,
        ];

        $result = $this->searchService->unsetSpecialQueryParams($filters);

        // Special params starting with _ should be removed
        $this->assertArrayNotHasKey('_search', $result);
        $this->assertArrayNotHasKey('_order', $result);
        $this->assertArrayNotHasKey('_limit', $result);
    }

    public function testParseQueryString(): void
    {
        $result = $this->searchService->parseQueryString('key1=value1&key2=value2');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('key1', $result);
        $this->assertArrayHasKey('key2', $result);
        $this->assertEquals('value1', $result['key1']);
        $this->assertEquals('value2', $result['key2']);
    }

    public function testParseQueryStringEmpty(): void
    {
        $result = $this->searchService->parseQueryString('');

        $this->assertIsArray($result);
    }
}
