<?php

/**
 * Golden test for the validation of a sitemap request.
 *
 * Resolves sitemap requests over scripted settings, category registry and
 * catalog search, and compares the outcome (accepted, or the status and body
 * of the error), the resolved register, schema and catalog, and the catalog
 * search with what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/SitemapRequestGoldenTest.php`.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapRequest;
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
 * A catalog row as the sitemap search returns it.
 */
class GoldenSitemapCatalog {

	/**
	 * @param array<string, mixed> $object The catalog's object data.
	 */
	public function __construct(
		private array $object,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->object;
	}
}

/**
 * Golden test for the sitemap request validation of SitemapService.
 */
class SitemapRequestGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/sitemap-request-golden.json';

	/**
	 * The settings, category and catalog each case reads.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$configuration = ['catalog_register' => 'cat-reg', 'catalog_schema' => 'cat-sch'];
		$wooRegister = [
			'title' => 'Woo',
			'id' => 'reg-woo',
			'schemas' => [
				['id' => 'sch-1', 'title' => ' Wetten en algemeen verbindende voorschriften '],
				['id' => 'sch-12', 'title' => 'Jaarplan of jaarverslag '],
			],
		];
		$law = ['label' => 'Wetten en algemeen verbindende voorschriften ', 'schemas' => []];

		return [
			'no settings' => [['settings' => []]],
			'unknown category' => [['settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration], 'category' => null]],
			'accepted, title-matched schema' => [[
				'settings' => ['availableRegisters' => [['title' => 'other', 'id' => 'reg-x', 'schemas' => []], $wooRegister], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => ['schemas' => ['sch-1', 'sch-2']],
			]],
			'accepted, jaarplan title mapping' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => ['label' => 'Jaarplannen en jaarverslagen', 'schemas' => []],
				'catalog' => ['schemas' => ['sch-12']],
			]],
			'last woo register wins' => [[
				'settings' => ['availableRegisters' => [$wooRegister, ['title' => 'WOO', 'id' => 'reg-2', 'schemas' => [['id' => 'sch-9', 'title' => 'Wetten en algemeen verbindende voorschriften']]]], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => ['schemas' => ['sch-9']],
			]],
			'no woo register, catalog scope lists' => [[
				'settings' => ['availableRegisters' => [['title' => 'other', 'id' => 'reg-x', 'schemas' => []]], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => ['registers' => ['r1'], 'schemas' => ['s1']],
			]],
			'named category schemas skip the title match' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => ['label' => 'Wetten en algemeen verbindende voorschriften', 'schemas' => ['own']],
				'catalog' => ['registers' => ['r1', ''], 'schemas' => ['sch-1', null]],
			]],
			'named category without catalog scope' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => ['label' => 'Wetten en algemeen verbindende voorschriften', 'schemas' => ['own']],
				'catalog' => ['registers' => [], 'schemas' => ['sch-1']],
			]],
			'catalog with registers but no schemas' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => ['label' => 'Something else', 'schemas' => []],
				'catalog' => ['registers' => ['r1'], 'schemas' => []],
			]],
			'unknown catalog' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => null,
			]],
			'schema outside the catalog' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => ['schemas' => ['sch-2']],
			]],
			'schema outside the catalog, scope lists kept' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => $law,
				'catalog' => ['registers' => ['r1'], 'schemas' => ['sch-2']],
			]],
			'no matching title' => [[
				'settings' => ['availableRegisters' => [$wooRegister], 'configuration' => $configuration],
				'category' => ['label' => 'Something else', 'schemas' => []],
				'catalog' => ['registers' => ['r1'], 'schemas' => ['s1']],
			]],
		];

	}//end cases()

	/**
	 * Validate one request and compare it with the recording.
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
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testValidationMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and validate the request.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The outcome, the resolved scope and the catalog searches.
	 */
	private function validate(array $case): array {
		$calls = [];

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($case['settings']);

		$categories = $this->createMock(WooCategoryRegistry::class);
		$categories->method('findBySitemapFile')->willReturnCallback(
			static function (string $sitemapFile) use (&$calls, $case): ?array {
				$calls[] = ['findBySitemapFile' => $sitemapFile];
				return ($case['category'] ?? null);
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false) use (&$calls, $case): array {
				$calls[] = ['searchObjectsPaginated' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'deleted' => $deleted];
				if (($case['catalog'] ?? null) === null) {
					return ['results' => []];
				}

				return ['results' => [new GoldenSitemapCatalog($case['catalog'])]];
			}
		);

		$service = new SitemapService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$settingsService,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IAppConfig::class),
			new TooiVocabularyService(),
			$this->createMock(PublicationQueryService::class),
			$categories
		);

		$request = new SitemapRequest(catalogSlug: 'the-catalog', categoryCode: 'sitemapindex-diwoo-infocat001.xml');
		$request->catalog = (object)[];
		$method = new \ReflectionMethod(SitemapService::class, 'resolveSitemapRequest');
		$outcome = ($method->invoke($service, $request, $objectService) ?? true);
		$catalog = $request->catalog;
		$schemaId = $request->schemaId;
		$registerId = $request->registerId;

		if ($outcome instanceof XMLResponse) {
			$outcome = ['status' => $outcome->getStatus(), 'body' => $outcome->render()];
		}

		if ($catalog instanceof GoldenSitemapCatalog) {
			$catalog = ['catalog' => $catalog->getObject()];
		} elseif (is_object($catalog) === true) {
			$catalog = get_class($catalog);
		}

		return [
			'outcome' => $outcome,
			'registerId' => $registerId,
			'schemaId' => $schemaId,
			'catalog' => $catalog,
			'calls' => $calls,
		];

	}//end validate()
}//end class
