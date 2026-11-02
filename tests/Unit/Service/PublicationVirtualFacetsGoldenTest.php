<?php

/**
 * Golden test for the directory and catalog facets the publication search adds.
 *
 * Pins what `PublicationService::addVirtualFieldFacets()` returns for each
 * combination of flags, for local catalogues with and without id or title,
 * for external catalogues that repeat a local one, for the empty default, and
 * when the directory lookup throws.
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
 * @spec openspec/specs/federation/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class PublicationVirtualFacetsGoldenTest extends TestCase {

	private const LOCAL = [
		['id' => 'c1', 'title' => 'One'],
		['id' => '', 'title' => 'Title only'],
		['title' => ''],
		['id' => 'c4'],
	];

	private const LISTINGS = [
		['catalog' => 'c1', 'title' => 'Repeat of a local one'],
		['id' => 'x2', 'title' => 'External'],
		['title' => 'No id'],
		['catalog' => 'x2', 'title' => 'Seen twice'],
		['catalog' => 'x3'],
	];

	/**
	 * @return array<string, array{0: array, 1: bool, 2: bool, 3: array, 4: array|null, 5: array}>
	 */
	public static function cases(): array {
		$dir = static fn (array $keys): array => ['type' => 'terms', 'buckets' => array_map(
			static fn (string $key): array => ['key' => $key, 'label' => $key, 'results' => 1],
			$keys
		)];
		$bucket = static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'results' => 1];
		$catalogs = ['type' => 'terms', 'buckets' => [
			$bucket('c1', 'One'),
			$bucket('Title only', 'Title only'),
			$bucket('unknown', 'unknown'),
			$bucket('c4', 'c4'),
			$bucket('x2', 'External'),
			$bucket('x3', 'x3'),
		]];
		$urls = ['https://a.example/pub', 'not a url', '', 'http://b.example:8080'];

		return [
			'no flags adds an empty @self' => [['facets' => ['x' => 1]], false, false, [], [], ['facets' => ['x' => 1], '@self' => []]],
			'no flags keeps an existing @self' => [['@self' => ['schema' => ['a']]], false, false, [], [], ['@self' => ['schema' => ['a']]]],
			'directories' => [['@self' => ['schema' => ['a']]], true, false, $urls, [], ['@self' => ['schema' => ['a'], 'directory' => $dir(['local', 'a.example', 'not a url', '', 'b.example'])]]],
			'no directories' => [[], true, false, [], [], ['@self' => ['directory' => $dir(['local'])]]],
			'catalogs' => [[], false, true, [], ['local' => self::LOCAL, 'listings' => self::LISTINGS], ['@self' => ['catalogs' => $catalogs]]],
			'no catalogs gives the default' => [[], false, true, [], ['local' => [], 'listings' => []], ['@self' => ['catalogs' => ['type' => 'terms', 'buckets' => [$bucket('default', 'Default Catalog')]]]]],
			'both' => [[], true, true, ['https://a.example'], ['local' => [['id' => 'c1', 'title' => 'One']], 'listings' => []], ['@self' => ['directory' => $dir(['local', 'a.example']), 'catalogs' => ['type' => 'terms', 'buckets' => [$bucket('c1', 'One')]]]]],
			'a failing directory lookup leaves the facets as they were' => [['q' => 1], true, true, 'throw', ['local' => self::LOCAL, 'listings' => []], ['q' => 1, '@self' => []]],
			'a failing listing lookup still lists the local catalogs' => [[], false, true, [], ['local' => [['id' => 'c1', 'title' => 'One']], 'listings' => 'throw'], ['@self' => ['catalogs' => ['type' => 'terms', 'buckets' => [$bucket('c1', 'One')]]]]],
		];
	}

	/**
	 * @param array        $facets      The facets the search returned.
	 * @param bool         $directories Whether to add directory facets.
	 * @param bool         $catalogs    Whether to add catalog facets.
	 * @param array|string $urls        What the directory lists, or 'throw'.
	 * @param array        $sources     Local catalogues and directory listings.
	 * @param array        $expected    The facets returned.
	 */
	#[DataProvider('cases')]
	public function testTheVirtualFacetsMatchTheRecordedOutput(array $facets, bool $directories, bool $catalogs, array|string $urls, array $sources, array $expected): void {
		$directoryService = $this->createMock(DirectoryService::class);
		if ($urls === 'throw') {
			$directoryService->method('getUniqueDirectories')->willThrowException(new \RuntimeException('directory down'));
		} else {
			$directoryService->method('getUniqueDirectories')->with(true)->willReturn($urls);
		}
		if (($sources['listings'] ?? null) === 'throw') {
			$directoryService->method('getDirectory')->willThrowException(new \RuntimeException('listings down'));
		} else {
			$directoryService->method('getDirectory')->willReturn(['results' => ($sources['listings'] ?? [])]);
		}

		$service = new PublicationService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$directoryService,
			$this->createMock(DownloadService::class),
			$this->createMock(IUserSession::class),
		);
		(new \ReflectionProperty(PublicationService::class, 'cachedLocalCatalogs'))->setValue($service, ($sources['local'] ?? []));

		$method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
		$this->assertSame($expected, $method->invoke($service, $facets, $directories, $catalogs));
	}
}
