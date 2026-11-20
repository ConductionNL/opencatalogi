<?php

/**
 * Unit tests for the sitemap index's pagination (woo-compliance Task 5).
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
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapIndexBuilder;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The index lists one sitemap page per batch of publications, each with the
 * newest update in its batch, and an empty index is still a valid document.
 *
 * @spec openspec/changes/archive/2026-11-20-woo-compliance/specs/woo-compliance/spec.md
 */
#[CoversClass(SitemapIndexBuilder::class)]
#[UsesClass(XMLResponse::class)]
class SitemapIndexPaginationTest extends TestCase {

	private const BASE = 'https://example.com/apps/opencatalogi/api/my-catalog/sitemaps/sitemap-diwoo-infocat001.xml';

	/**
	 * The publication counts and the pages the index lists for each.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function counts(): array {
		return [
			'2500 publications' => [2500, 3],
			'exactly 1000' => [1000, 1],
			'1001' => [1001, 2],
			'none' => [0, 0],
		];
	}//end counts()

	/**
	 * Each page of 1000 is one index entry pointing at its page number.
	 *
	 * @param int $total The publications.
	 * @param int $pages The entries expected.
	 *
	 * @return void
	 */
	#[DataProvider('counts')]
	public function testOneEntryPerPageOfAThousand(int $total, int $pages): void {
		$entries = $this->builder(total: $total)->entries(queries: [['register' => 'r', 'schema' => 's']], baseUri: self::BASE);

		$this->assertCount(expectedCount: $pages, haystack: $entries);
		foreach ($entries as $index => $entry) {
			$this->assertSame(expected: self::BASE . '?page=' . ($index + 1), actual: $entry['loc']);
		}
	}//end testOneEntryPerPageOfAThousand()

	/**
	 * Each entry's lastmod is the update of the first, newest, publication of its batch.
	 *
	 * @return void
	 */
	public function testEachEntryCarriesTheNewestUpdateOfItsBatch(): void {
		$entries = $this->builder(total: 2500)->entries(queries: [['register' => 'r', 'schema' => 's']], baseUri: self::BASE);

		$this->assertSame(
			expected: [self::updated(index: 0), self::updated(index: 1000), self::updated(index: 2000)],
			actual: array_column($entries, 'lastmod')
		);
	}//end testEachEntryCarriesTheNewestUpdateOfItsBatch()

	/**
	 * With 1001 publications page 2 holds the one that is left.
	 *
	 * @return void
	 */
	public function testThePageAfterAFullOneHoldsTheRest(): void {
		$page = $this->builder(total: 1001)->page(queries: [['register' => 'r', 'schema' => 's']], page: 2);

		$this->assertCount(expectedCount: 1, haystack: $page['results']);
		$this->assertSame(expected: 'pub-1000', actual: $page['results'][0]->jsonSerialize()['id']);
		$this->assertSame(expected: [], actual: $page['continuing']);
	}//end testThePageAfterAFullOneHoldsTheRest()

	/**
	 * Without publications the index is a sitemapindex without children, answered 200.
	 *
	 * @return void
	 */
	public function testAnEmptyIndexIsAValidDocument(): void {
		$builder = $this->builder(total: 0);
		$response = $builder->response(sitemaps: $builder->entries(queries: [['register' => 'r', 'schema' => 's']], baseUri: self::BASE));

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$dom = new \DOMDocument();
		$dom->loadXML($response->render());
		$this->assertSame(expected: 'sitemapindex', actual: $dom->documentElement->localName);
		$this->assertSame(expected: 0, actual: $dom->documentElement->getElementsByTagName('*')->length);
	}//end testAnEmptyIndexIsAValidDocument()

	/**
	 * A builder over a search that pages `$total` publications, newest first.
	 *
	 * @param int $total The publications.
	 *
	 * @return SitemapIndexBuilder
	 */
	private function builder(int $total): SitemapIndexBuilder {
		$search = new class($total) {
			/**
			 * Constructor.
			 *
			 * @param int $total The publications.
			 */
			public function __construct(private readonly int $total) {
			}

			/**
			 * One page of the publications, honouring `_limit` and `_page`.
			 *
			 * @param array $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 * @param bool $deleted Whether deleted objects are included.
			 *
			 * @return array
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false): array {
				$offset = (($query['_page'] - 1) * $query['_limit']);
				$results = [];
				for ($i = $offset; $i < min($this->total, $offset + $query['_limit']); $i++) {
					$results[] = new class($i) {
						/**
						 * Constructor.
						 *
						 * @param int $index The publication's position.
						 */
						public function __construct(private readonly int $index) {
						}

						/**
						 * The publication.
						 *
						 * @return array
						 */
						public function jsonSerialize(): array {
							return ['id' => 'pub-' . $this->index, '@self' => ['updated' => SitemapIndexPaginationTest::updated(index: $this->index)]];
						}
					};
				}

				$next = null;
				if ($offset + $query['_limit'] < $this->total) {
					$next = 'cursor';
				}

				return ['results' => $results, 'total' => $this->total, 'next' => $next];
			}
		};

		return new SitemapIndexBuilder(objectService: $search, perPage: 1000);
	}//end builder()

	/**
	 * The update of the publication at a position: newer for a lower position.
	 *
	 * @param int $index The position.
	 *
	 * @return string The moment.
	 */
	public static function updated(int $index): string {
		return date('Y-m-d H:i:s', (1767225600 - ($index * 60)));
	}//end updated()
}//end class
