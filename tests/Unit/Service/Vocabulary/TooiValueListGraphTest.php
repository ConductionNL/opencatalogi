<?php

/**
 * Tests for the TOOI download to import-graph rewrite (REQ-WVC-002).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\Vocabulary\TooiValueListGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The rewrite on the four recorded TOOI downloads.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
#[CoversClass(TooiValueListGraph::class)]
class TooiValueListGraphTest extends TestCase {

	/**
	 * A recorded download, decoded.
	 *
	 * @param string $file The fixture name.
	 *
	 * @return mixed
	 */
	private static function recorded(string $file): mixed {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/tooi/' . $file), true);
	}//end recorded()

	/**
	 * A schema-based list keeps its own scheme node, retyped to a single type.
	 *
	 * @return void
	 */
	public function testASchemeBasedListKeepsItsScheme(): void {
		$scheme = 'https://identifier.overheid.nl/tooi/def/thes/xtrn/taal';
		$built = (new TooiValueListGraph())->build(decoded: self::recorded(file: 'scw_talen_1.json'), schemeUri: $scheme);

		$graph = $built['jsonLd']['@graph'];
		$this->assertSame($scheme, $graph[0]['@id']);
		$this->assertSame('http://www.w3.org/2004/02/skos/core#ConceptScheme', $graph[0]['@type']);
		$this->assertCount(27, $built['conceptUris']);
		foreach (array_slice($graph, 1) as $node) {
			$this->assertSame('http://www.w3.org/2004/02/skos/core#Concept', $node['@type']);
			$this->assertSame([['@id' => $scheme]], $node['http://www.w3.org/2004/02/skos/core#inScheme']);
		}
	}//end testASchemeBasedListKeepsItsScheme()

	/**
	 * A collection-based list has its list node as the scheme and drops grouping concepts.
	 *
	 * @return void
	 */
	public function testACollectionBasedListBecomesAScheme(): void {
		$scheme = 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten';
		$built = (new TooiValueListGraph())->build(decoded: self::recorded(file: 'ccw_plooi_documentsoorten_7.json'), schemeUri: $scheme);

		$graph = $built['jsonLd']['@graph'];
		$this->assertSame($scheme, $graph[0]['@id']);
		$this->assertSame('http://www.w3.org/2004/02/skos/core#ConceptScheme', $graph[0]['@type']);
		$this->assertCount(91, $built['conceptUris']);
		foreach ($built['conceptUris'] as $uri) {
			$this->assertStringStartsWith('https://identifier.overheid.nl/tooi/def/thes/kern/c_', $uri, 'no grouping concept');
		}

		$handelingen = (new TooiValueListGraph())->build(
			decoded: self::recorded(file: 'ccw_plooi_documenthandelingen_2.json'),
			schemeUri: 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen'
		);
		$this->assertCount(3, $handelingen['conceptUris'], 'ontvangst, vaststelling, ondertekening');
	}//end testACollectionBasedListBecomesAScheme()

	/**
	 * OpenRegister's own node reader sees one scheme and concepts in the rewritten graph.
	 *
	 * @return void
	 */
	public function testOpenRegistersReaderSeesTheSchemeAndTheConcepts(): void {
		$reader = 'OCA\\OpenRegister\\Service\\SkosNodeReader';
		if (class_exists($reader) === false) {
			$this->markTestSkipped('OpenRegister is not beside the app, so its SkosNodeReader cannot be loaded.');
		}

		$built = (new TooiValueListGraph())->build(
			decoded: self::recorded(file: 'scw_woo_informatiecategorieen_4.json'),
			schemeUri: 'https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen'
		);
		$skos = new $reader();
		$types = array_map(static fn (array $node): string => $skos->localName(iriOrPrefixed: (string)$node['@type']), $built['jsonLd']['@graph']);
		$this->assertSame(['ConceptScheme'], array_values(array_unique(array_slice($types, 0, 1))));
		$this->assertSame(['Concept'], array_values(array_unique(array_slice($types, 1))));
		$labels = $skos->langMap(value: $skos->findPredicate(node: $this->nodeOf(graph: $built['jsonLd']['@graph'], id: 'c_99a836c7'), localName: 'prefLabel'));
		$this->assertSame('advies', $labels['nl'], 'the Dutch label reads through the expanded literal');
	}//end testOpenRegistersReaderSeesTheSchemeAndTheConcepts()

	/**
	 * A download without a scheme or a concept is refused.
	 *
	 * @return void
	 */
	public function testADownloadWithoutSchemeOrConceptIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TooiValueListGraph())->build(decoded: [['@id' => 'https://example.test/x', '@type' => ['https://example.test/Thing']]], schemeUri: 'https://example.test/s');
	}//end testADownloadWithoutSchemeOrConceptIsRefused()

	/**
	 * A download that is no node list is refused.
	 *
	 * @return void
	 */
	public function testADownloadThatIsNoNodeListIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TooiValueListGraph())->build(decoded: ['title' => 'maintenance'], schemeUri: 'https://example.test/s');
	}//end testADownloadThatIsNoNodeListIsRefused()

	/**
	 * A node of the graph by its local id.
	 *
	 * @param array<int, array<string, mixed>> $graph The graph.
	 * @param string                           $id    The local id.
	 *
	 * @return array<string, mixed>
	 */
	private function nodeOf(array $graph, string $id): array {
		foreach ($graph as $node) {
			if (str_ends_with((string)$node['@id'], $id) === true) {
				return $node;
			}
		}

		$this->fail('no node ' . $id);
	}//end nodeOf()
}//end class
