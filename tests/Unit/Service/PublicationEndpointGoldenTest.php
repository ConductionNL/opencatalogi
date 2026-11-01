<?php

/**
 * Golden test for the publication endpoint detection of a directory listing.
 *
 * Runs `DirectoryService::detectPublicationEndpoint()` over listings that
 * reach every source it tries in turn: an explicit endpoint, the search URL
 * (by substring and by rebuilding the URL), the catalog directory, the
 * directory host or URL, and a domain-like title or name. Compares each
 * result with what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/PublicationEndpointGoldenTest.php`.
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
 *
 * @spec openspec/specs/dashboard/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DirectoryService::detectPublicationEndpoint().
 */
class PublicationEndpointGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/publication-endpoint-golden.json';

	/**
	 * The listings, keyed by case name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function listings(): array {
		return [
			'explicit publications' => ['publications' => 'https://a/pubs', 'search' => 'https://a/search'],
			'explicit publication' => ['publications' => '', 'publication' => 'https://a/pub', 'search' => 'https://a/search'],
			'search with segment' => ['search' => 'https://a.nl/apps/opencatalogi/api/search'],
			'search with segment and query' => ['search' => 'https://a.nl/api/search?_limit=5'],
			'search substring twice' => ['search' => 'https://a.nl/search/searchable/search'],
			'search substring inside a word' => ['search' => 'https://a.nl/api/searching'],
			'search rebuilt with port and query' => ['search' => 'https://a.nl:8443/api/find/?q=1'],
			'search rebuilt drops the fragment' => ['search' => 'https://a.nl/api/find#top'],
			'search already canonical' => ['search' => 'https://a.nl/api/find', 'catalogDirectory' => 'https://c.nl/api/directory'],
			'search without path' => ['search' => 'https://a.nl', 'directory' => 'dir.nl'],
			'catalog directory' => ['catalogDirectory' => 'https://c.nl/apps/opencatalogi/api/directory/x'],
			'catalog directory before directory' => ['catalogDirectory' => 'https://c.nl/api/directory', 'directory' => 'd.nl', 'title' => 't.nl'],
			'catalog directory without api path' => ['catalogDirectory' => 'https://c.nl/dir', 'directory' => 'https://d.nl:8080/x/y?z=1'],
			'directory hostname' => ['directory' => 'directory.opencatalogi.nl'],
			'directory url' => ['directory' => 'http://d.nl/apps/opencatalogi/api/directory'],
			'directory url without host' => ['directory' => 'file:///tmp/x', 'title' => 'Example.COM/path'],
			'title domain' => ['title' => ' OpenCatalogi.nl '],
			'title with backslash' => ['title' => 'Example.com\\sub'],
			'title with a space' => ['title' => 'Open Catalogi.nl', 'name' => 'name.nl'],
			'name domain' => ['name' => 'Name.Example.org'],
			'title without dot' => ['title' => 'Catalog'],
			'title only a slash' => ['title' => '/.x'],
			'empty listing' => [],
		];

	}//end listings()

	/**
	 * Every listing resolves to the recorded endpoint.
	 *
	 * @return void
	 */
	public function testEndpointsMatchTheRecording(): void {
		$service = (new \ReflectionClass(DirectoryService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(DirectoryService::class, 'detectPublicationEndpoint');

		$actual = [];
		foreach (self::listings() as $name => $listing) {
			$actual[$name] = $method->invoke($service, $listing);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($recorded, $actual);

	}//end testEndpointsMatchTheRecording()
}//end class
