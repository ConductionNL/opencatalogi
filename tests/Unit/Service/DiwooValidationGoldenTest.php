<?php

/**
 * Golden test for `SitemapService::validateDiwooOutput()`.
 *
 * Runs the DiWoo dry-run validator over scripted settings, category registry,
 * OpenRegister search and file service, and compares the report and every
 * search and file lookup with what was recorded: OpenRegister missing, a
 * refused request, the page clamp, entity and array rows, files without a
 * download URL, and the value-list violations.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/DiwooValidationGoldenTest.php`.
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
 * @uses \OCA\OpenCatalogi\Http\XMLResponse
 * @uses \OCA\OpenCatalogi\Service\SitemapRequest
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for SitemapService::validateDiwooOutput().
 */
class DiwooValidationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/diwoo-validation-golden.json';

	/**
	 * The apps, category, catalog, rows and files of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$law = ['label' => 'Wetten en algemeen verbindende voorschriften', 'schemas' => []];
		$catalog = ['schemas' => ['sch-1']];
		$pdf = ['downloadUrl' => 'https://dl.example/1.pdf', 'extension' => 'pdf', 'published' => '2026-01-02 10:00:00'];
		return [
			'openregister missing' => [['apps' => []]],
			'unknown category' => [['category' => null]],
			'no publications' => [['category' => $law, 'catalog' => $catalog, 'rows' => []]],
			'page clamped to one' => [['category' => $law, 'catalog' => $catalog, 'rows' => [], 'page' => -3]],
			'page three' => [['category' => $law, 'catalog' => $catalog, 'rows' => [], 'page' => 3]],
			'publications with files' => [[
				'category' => $law,
				'catalog' => $catalog,
				'rows' => [
					['id' => 'p1', '@self' => ['created' => '2026-01-01', 'updated' => '2026-01-03']],
					['entity' => ['id' => 'p2', 'publicationDate' => '2026-02-01']],
				],
				'files' => [
					'p1' => [$pdf, ['extension' => 'txt']],
					'p2' => [['downloadUrl' => 'https://dl.example/2.odt', 'extension' => 'odt']],
				],
			]],
			'publication without files' => [[
				'category' => $law,
				'catalog' => $catalog,
				'rows' => [['id' => 'p3']],
				'files' => [],
			]],
		];

		}//end cases()

	/**
	 * Validate one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testValidationMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->validate(case: $case);

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

	}//end testValidationMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and run the validator.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The report, the searches and the file lookups.
	 */
	private function validate(array $case): array {
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

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnArgument(0);

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

		$pages = ($case['rows'] ?? []);
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

				$rows = [];
				foreach ($pages as $row) {
					if (isset($row['entity']) === true) {
						$row = DiwooValidationGoldenTest::makeEntity($row['entity']);
					}

					$rows[] = $row;
				}

				return ['results' => $rows];
			}
		);

		$files = ($case['files'] ?? []);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			static function (mixed $object) use (&$calls, $files): array {
				$calls[] = ['getFiles', $object];
				return ($files[$object] ?? []);
			}
		);
		$fileService->method('formatFiles')->willReturnCallback(
			static fn (array $list): array => ['results' => $list]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\OpenRegister\\Service\\FileService' => $fileService,
				default => $objectService,
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));
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
		$report = $service->validateDiwooOutput('the-catalog', 'sitemapindex-diwoo-infocat001.xml', ($case['page'] ?? 1));

		return ['report' => $report, 'calls' => $calls];

	}//end validate()

	/**
	 * A search row served as an object whose jsonSerialize() returns the data.
	 *
	 * @param array<string, mixed> $data The row.
	 *
	 * @return object
	 */
	public static function makeEntity(array $data): object {
		return new class($data) {
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
	}//end makeEntity()
}//end class
