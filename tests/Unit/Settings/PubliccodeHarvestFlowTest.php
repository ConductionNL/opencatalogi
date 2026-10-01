<?php

/**
 * Tests for the shipped GitHub publiccode harvest flow and its shard file.
 *
 * Both are configuration, so nothing about them fails loudly at run time. A
 * shard edge drawn from another shard instead of from the trigger multiplied
 * 12 runs into 3,472 on 2026-08-13 and still validated. A gap between two size
 * ranges loses every file in it without an error. A flow shipped enabled starts
 * crawling GitHub the moment the app is installed. Each of those is asserted
 * here because nothing else would notice.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\PubliccodeHarvestService
 */
class PubliccodeHarvestFlowTest extends TestCase {

	/**
	 * The largest file GitHub code search indexes, in bytes (384 KB).
	 *
	 * @var int
	 */
	private const GITHUB_MAX_INDEXED_SIZE = 393216;

	/**
	 * The parsed harvest fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * The parsed shard file.
	 *
	 * @var array<string, mixed>
	 */
	private array $shards;

	/**
	 * Parse both files once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$root = __DIR__ . '/../../..';
		$this->fragment = (array)json_decode(
			(string)file_get_contents($root . '/lib/Settings/register.d/publiccode-github-harvest.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->shards = (array)json_decode(
			(string)file_get_contents($root . PubliccodeHarvestService::SHARDS_FILE),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}//end setUp()

	/**
	 * The one shipped flow.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(): array {
		$flows = $this->fragment['components']['schemas']['publiccode']['configuration']['x-openregister-flows'];
		$this->assertCount(1, $flows);

		return (array)$flows[0];
	}//end flow()

	/**
	 * Node ids of a given type.
	 *
	 * @param string $type The node type.
	 *
	 * @return array<int, string>
	 */
	private function nodeIdsOfType(string $type): array {
		$ids = [];
		foreach ($this->flow()['nodes'] as $node) {
			if ($node['type'] === $type) {
				$ids[] = (string)$node['id'];
			}
		}

		return $ids;
	}//end nodeIdsOfType()

	/**
	 * The flow ships disabled, under the name the admin service looks it up by.
	 *
	 * @return void
	 */
	public function testTheFlowShipsDisabledUnderTheNameTheServiceFinds(): void {
		$flow = $this->flow();

		$this->assertFalse($flow['enabled']);
		$this->assertSame(PubliccodeHarvestService::FLOW_NAME, $flow['name']);
		$this->assertSame(PubliccodeHarvestService::FLOW_APP, $flow['app']);
	}//end testTheFlowShipsDisabledUnderTheNameTheServiceFinds()

	/**
	 * Both triggers reach every shard directly, and no shard reaches another.
	 *
	 * @return void
	 */
	public function testEveryShardHangsDirectlyOffBothTriggersAndNeverOffAnotherShard(): void {
		$flow = $this->flow();
		$shardIds = $this->nodeIdsOfType('openconnector.source-paginate');
		$triggers = array_merge(
			$this->nodeIdsOfType('openregister.trigger-schedule'),
			$this->nodeIdsOfType('openregister.trigger-manual')
		);

		$this->assertCount(24, $shardIds);
		$this->assertCount(2, $triggers);

		$edges = [];
		foreach ($flow['edges'] as $edge) {
			$edges[] = $edge['from'] . '>' . $edge['to'];
			$this->assertFalse(
				in_array($edge['from'], $shardIds, true) === true && in_array($edge['to'], $shardIds, true) === true,
				'A shard must never feed another shard: ' . $edge['from'] . ' -> ' . $edge['to']
			);
		}

		foreach ($triggers as $trigger) {
			foreach ($shardIds as $shard) {
				$this->assertContains($trigger . '>' . $shard, $edges);
			}
		}

		// Each shard leads into its OWN tail and nowhere else. A shared tail
		// loses pages: a place several steps write into keeps only the last
		// step's items (measured 2026-10-01: 3 of 24 shards' pages processed).
		foreach ($shardIds as $shard) {
			$out = array_values(array_filter($flow['edges'], static fn (array $e): bool => $e['from'] === $shard));
			$this->assertCount(1, $out);
			$this->assertSame('hits-' . substr($shard, -2), $out[0]['to']);
		}

		$into = [];
		foreach ($flow['edges'] as $edge) {
			$into[$edge['to']][] = $edge['from'];
		}

		foreach ($flow['nodes'] as $node) {
			if (in_array($node['type'], ['openregister.end', 'openconnector.source-paginate'], true) === true) {
				continue;
			}

			if (str_starts_with($node['type'], 'openregister.trigger-') === true) {
				continue;
			}

			$this->assertCount(1, $into[$node['id']], 'Only the end node may have more than one way in: ' . $node['id']);
		}

		// The end node stops the WHOLE run, so it must wait for every shard.
		// Without the join the first shard to finish stopped the other 23
		// (measured 2026-10-01: run stopped with 13 shards still suspended).
		$end = array_column($flow['nodes'], null, 'id')['end'];
		$this->assertSame('openregister.end', $end['type']);
		$this->assertTrue($end['join']);
		$this->assertCount(24, $into['end']);

	}//end testEveryShardHangsDirectlyOffBothTriggersAndNeverOffAnotherShard()

	/**
	 * The tail fetches, drops what did not decode, maps and writes, in that order.
	 *
	 * @return void
	 */
	public function testTheTailFetchesFiltersMapsAndWritesBySlug(): void {
		$flow = $this->flow();
		$nodes = array_column($flow['nodes'], null, 'id');
		$next = [];
		foreach ($flow['edges'] as $edge) {
			$next[$edge['from']][] = $edge['to'];
		}

		foreach ($this->nodeIdsOfType('openconnector.source-paginate') as $shard) {
			$n = substr($shard, -2);
			$chain = [$shard];
			while (isset($next[end($chain)]) === true) {
				$this->assertCount(1, $next[end($chain)], 'The tail is linear.');
				$chain[] = $next[end($chain)][0];
			}

			$this->assertSame([$shard, 'hits-' . $n, 'fetch-' . $n, 'decoded-' . $n, 'map-' . $n, 'write-' . $n, 'end'], $chain);
			$this->assertSame('openconnector.source-call', $nodes['fetch-' . $n]['type']);
			$this->assertSame('github-raw', $nodes['fetch-' . $n]['config']['source']);
			$this->assertSame('yaml', $nodes['fetch-' . $n]['config']['decode']);
			$this->assertSame('continue', $nodes['fetch-' . $n]['onError']);
			// source-call reads onError from its config: a node-level key alone lets one bad file fail the whole page.
			$this->assertSame('continue', $nodes['fetch-' . $n]['config']['onError']);
			$this->assertSame('openregister.map', $nodes['map-' . $n]['type']);
			$this->assertSame('publiccode-github-hit', $nodes['map-' . $n]['config']['mapping']);
			$this->assertSame('upsert', $nodes['write-' . $n]['config']['operation']);
			$this->assertSame('publiccode', $nodes['write-' . $n]['config']['schema']);
			$this->assertSame(['@self.slug' => '{{ component.slug }}'], $nodes['write-' . $n]['config']['match']);
			$this->assertGreaterThanOrEqual(1000, $nodes['write-' . $n]['config']['maxWrites']);
		}

		$this->assertArrayHasKey('publiccode-github-hit', $this->fragment['components']['mappings']);
	}//end testTheTailFetchesFiltersMapsAndWritesBySlug()

	/**
	 * Every shard node names a synchronization in the shard file, and the other way round.
	 *
	 * @return void
	 */
	public function testTheShardFileAndTheFlowAgree(): void {
		$fromFlow = [];
		foreach ($this->flow()['nodes'] as $node) {
			if ($node['type'] === 'openconnector.source-paginate') {
				$fromFlow[$node['id']] = $node['config']['synchronization'];
			}
		}

		$fromFile = [];
		foreach ($this->shards['shards'] as $shard) {
			$fromFile[$shard['node']] = $shard['synchronization'];
		}

		ksort($fromFlow);
		ksort($fromFile);
		$this->assertSame($fromFile, $fromFlow);
	}//end testTheShardFileAndTheFlowAgree()

	/**
	 * The size ranges cover 0 to GitHub's indexing limit with no gap and no overlap.
	 *
	 * @return void
	 */
	public function testTheSizeRangesAreContiguousFromZeroToTheIndexingLimit(): void {
		$expectedMin = 0;
		foreach ($this->shards['shards'] as $shard) {
			$this->assertSame($expectedMin, $shard['min'], 'Gap or overlap before ' . $shard['node']);
			$this->assertGreaterThanOrEqual($shard['min'], $shard['max']);
			$expectedMin = ($shard['max'] + 1);
		}

		$this->assertSame(self::GITHUB_MAX_INDEXED_SIZE + 1, $expectedMin);
	}//end testTheSizeRangesAreContiguousFromZeroToTheIndexingLimit()

	/**
	 * A shard's synchronization searches its own range, 100 a page, one page at a time.
	 *
	 * @return void
	 */
	public function testEachShardBecomesAPagedOneAtATimeCodeSearch(): void {
		$shard = $this->shards['shards'][1];
		$synchronization = PubliccodeHarvestService::synchronizationFor(definitions: $this->shards, shard: $shard);

		$this->assertSame($shard['synchronization'], $synchronization['slug']);
		$this->assertSame('github-api', $synchronization['sourceId']);
		$this->assertSame('/search/code', $synchronization['sourceConfig']['endpoint']);
		$this->assertSame(
			'filename:publiccode.yml size:' . $shard['min'] . '..' . $shard['max'],
			$synchronization['sourceConfig']['query']['q']
		);
		$this->assertSame(100, $synchronization['sourceConfig']['query']['per_page']);
		$this->assertSame(10, $synchronization['sourceConfig']['maxPages']);
		$this->assertSame(1, $synchronization['sourceConfig']['prefetchConcurrency']);
		$this->assertSame('items', $synchronization['sourceConfig']['resultsPosition']);
	}//end testEachShardBecomesAPagedOneAtATimeCodeSearch()

	/**
	 * The Componenten catalogue names the publiccode schema, and that schema is publicly readable.
	 *
	 * @return void
	 */
	public function testTheComponentenCatalogueIsPublishedOverAPubliclyReadableSchema(): void {
		$catalogue = null;
		foreach ($this->fragment['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'catalog' && ($object['slug'] ?? '') === 'componenten') {
				$catalogue = $object;
			}
		}

		$this->assertNotNull($catalogue);
		$this->assertSame(['publiccode'], $catalogue['schemas']);
		$this->assertSame(['publication'], $catalogue['registers']);
		$this->assertTrue($catalogue['listed']);
		$this->assertLessThanOrEqual(time(), strtotime($catalogue['published']));

		$schemaFragment = (array)json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/publiccode-software.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame(['public'], $schemaFragment['components']['schemas']['publiccode']['authorization']['read']);
	}//end testTheComponentenCatalogueIsPublishedOverAPubliclyReadableSchema()
}//end class
