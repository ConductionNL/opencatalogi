<?php

/**
 * Golden test for `SitemapService::buildSitemapIndex()`.
 *
 * Builds category sitemap indexes over scripted settings, category registry,
 * read-rule guard and OpenRegister search, and compares the response (status
 * and rendered XML) and every search with what was recorded: a refused
 * request, a category with no source, one source over several pages, two
 * sources that run out at different pages, a schema the guard drops, and the
 * newest `updated` as `lastmod`.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/SitemapIndexGoldenTest.php`.
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
 * @spec openspec/specs/woo-compliance/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\SitemapService
 * @uses \OCA\OpenCatalogi\Service\SitemapIndexBuilder
 * @uses \OCA\OpenCatalogi\Http\XMLResponse
 * @uses \OCA\OpenCatalogi\Service\SitemapRequest
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for SitemapService::buildSitemapIndex().
 */
class SitemapIndexGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/sitemap-index-golden.json';

	/**
	 * The catalog, category, guard and pages of each case.
	 *
	 * `pages` maps a source key (`category` for the wooCategory search, `woo`
	 * for the title-matched schema) to its pages; each page lists
	 * [id, updated] rows and whether a next page exists.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$law = ['label' => 'Wetten en algemeen verbindende voorschriften', 'schemas' => []];
		return [
			'unknown category' => [['category' => null]],
			'no source' => [['category' => ['label' => 'Iets anders', 'schemas' => []], 'catalog' => ['schemas' => ['sch-1']]]],
			'no rows' => [['category' => $law, 'catalog' => ['registers' => ['r1'], 'schemas' => ['sch-1']], 'pages' => []]],
			'one source, three pages' => [[
				'category' => ['label' => 'Iets anders', 'schemas' => []],
				'catalog' => ['registers' => ['r1'], 'schemas' => ['sch-1']],
				'pages' => ['category' => [
					[[['a', '2026-01-01T00:00:00+00:00'], ['b', '2026-03-01T00:00:00+00:00']], true],
					[[['c', '2026-02-01T00:00:00+00:00']], true],
					[[['d', null]], false],
				]],
			]],
			'two sources run out apart' => [[
				'category' => $law,
				'catalog' => ['registers' => ['r1'], 'schemas' => ['sch-1']],
				'pages' => [
					'category' => [
						[[['a', '2026-01-01T00:00:00+00:00'], ['shared', '2026-05-01T00:00:00+00:00']], false],
					],
					'woo' => [
						[[['shared', '2026-05-01T00:00:00+00:00'], ['w1', '2026-04-01T00:00:00+00:00']], true],
						[[['w2', '2026-06-01T00:00:00+00:00']], true],
						[[], false],
					],
				],
			]],
			'next page that is empty' => [[
				'category' => $law,
				'catalog' => ['schemas' => ['sch-1']],
				'pages' => ['woo' => [[[['w1', '2026-04-01T00:00:00+00:00']], true], [[], true]]],
			]],
			'guard drops the woo schema' => [[
				'category' => $law,
				'catalog' => ['registers' => ['r1'], 'schemas' => ['sch-1']],
				'guardDrops' => true,
				'pages' => ['woo' => [[[['w1', '2026-04-01T00:00:00+00:00']], false]]],
			]],
		];

	}//end cases()

	/**
	 * Build one index and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testIndexMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->index(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testIndexMatchesTheRecording()

	/**
	 * A search row with an id and an `updated` stamp.
	 *
	 * @param string $id The id.
	 * @param string|null $updated The update stamp.
	 *
	 * @return object
	 */
	private static function row(string $id, ?string $updated): object {
		return new class(['id' => $id, '@self' => ['updated' => $updated]]) {
			/**
			 * @param array<string, mixed> $data The row.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end row()

	/**
	 * Build the service over scripted collaborators and build the index.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The response and the searches.
	 */
	private function index(array $case): array {
		$calls = [];
		$wooRegister = [
			'title' => 'Woo',
			'id' => 'reg-woo',
			'schemas' => [['id' => 'sch-1', 'title' => 'Wetten en algemeen verbindende voorschriften']],
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn(
			['availableRegisters' => [$wooRegister], 'configuration' => ['catalog_register' => 'cat-reg', 'catalog_schema' => 'cat-sch']]
		);

		$categories = $this->createMock(WooCategoryRegistry::class);
		$categories->method('findBySitemapFile')->willReturn(($case['category'] ?? null));
		$categories->method('schemasFor')->willReturn([]);

		$guardDrops = ($case['guardDrops'] ?? false);
		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnCallback(
			static fn (array $schemaIds): array => ($guardDrops === true && $schemaIds === ['sch-1'] ? [] : $schemaIds)
		);

		$catalogData = ($case['catalog'] ?? null);
		$catalog = null;
		if ($catalogData !== null) {
			$catalog = new class($catalogData) {
				/**
				 * @param array<string, mixed> $object The catalog data.
				 */
				public function __construct(private array $object) {
				}

				/**
				 * @return array<string, mixed>
				 */
				public function getObject(): array {
					return $this->object;
				}

				/**
				 * @return string
				 */
				public function getSlug(): string {
					return 'the-catalog';
				}
			};
		}

		$pages = ($case['pages'] ?? []);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false) use (&$calls, $catalog, $pages): array {
				$calls[] = ['searchObjectsPaginated', $query, $_rbac, $_multitenancy, $deleted];
				if (isset($query['slug']) === true) {
					if ($catalog === null) {
						return ['results' => []];
					}

					return ['results' => [$catalog]];
				}

				$source = 'woo';
				if (isset($query['wooCategory']) === true) {
					$source = 'category';
				}

				$page = ($pages[$source][(int)$query['_page'] - 1] ?? [[], false]);
				$rows = array_map(static fn (array $row): object => SitemapIndexGoldenTest::makeRow($row[0], $row[1]), $page[0]);
				$answer = ['results' => $rows];
				if ($page[1] === true) {
					$answer['next'] = 'more';
				}

				return $answer;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://catalogus.example/');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(2);

		$service = new SitemapService(
			$container,
			$apps,
			$settings,
			$urls,
			$config,
			new TooiVocabularyService(),
			$queryService,
			$categories
		);
		$response = $service->buildSitemapIndex('the-catalog', 'sitemapindex-diwoo-infocat001.xml');

		return ['status' => $response->getStatus(), 'body' => $response->render(), 'calls' => $calls];

	}//end index()

	/**
	 * A search row, for the scripted store.
	 *
	 * @param string $id The id.
	 * @param string|null $updated The update stamp.
	 *
	 * @return object
	 */
	public static function makeRow(string $id, ?string $updated): object {
		return self::row(id: $id, updated: $updated);
	}//end makeRow()
}//end class
