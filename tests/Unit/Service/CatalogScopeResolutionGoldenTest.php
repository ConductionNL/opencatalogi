<?php

/**
 * Golden test for `PublicationQueryService::resolveCatalogScope()`.
 *
 * Calls the scope resolution of the public search directly over a scripted
 * CatalogiService and compares the scope, the catalogue lookups and every log
 * line with what was recorded: the single `_catalog`, the `_catalogi` union
 * with its skipped entries, the default union of listed and published
 * catalogues, unpublished and future catalogues, and the failure paths.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/CatalogScopeResolutionGoldenTest.php`.
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
 * @spec openspec/specs/search/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeIds
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for PublicationQueryService::resolveCatalogScope().
 */
class CatalogScopeResolutionGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/catalog-scope-resolution-golden.json';

	private const PAST = '2020-01-01T00:00:00+00:00';

	private const FUTURE = '2999-01-01T00:00:00+00:00';

	/**
	 * The catalogues the scripted CatalogiService knows, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function catalogs(): array {
		return [
			'woo' => ['slug' => 'woo', 'registers' => [1, 2], 'schemas' => [10, 11], 'published' => self::PAST],
			'open' => ['slug' => 'open', 'registers' => [2, 3], 'schemas' => [11, 12], 'published' => self::PAST],
			'draft' => ['slug' => 'draft', 'registers' => [9], 'schemas' => [90]],
			'later' => ['slug' => 'later', 'registers' => [8], 'schemas' => [80], 'published' => self::FUTURE],
			'bad-date' => ['slug' => 'bad-date', 'registers' => [7], 'schemas' => [70], 'published' => 'not a date'],
			'named' => ['slug' => 'named', 'registers' => ['publication'], 'schemas' => ['document'], 'published' => self::PAST],
			'mixed' => ['slug' => 'mixed', 'registers' => ['3', 'x'], 'schemas' => [12, '13'], 'published' => self::PAST],
		];
	}//end catalogs()

	/**
	 * The query parameters and service state of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'single published catalog' => [['params' => ['_catalog' => 'woo']]],
			'single unknown catalog' => [['params' => ['_catalog' => 'nope']]],
			'single unpublished catalog' => [['params' => ['_catalog' => 'draft']]],
			'single future catalog' => [['params' => ['_catalog' => 'later']]],
			'single bad date' => [['params' => ['_catalog' => 'bad-date']]],
			'single named scope' => [['params' => ['_catalog' => 'named']]],
			'single mixed scope' => [['params' => ['_catalog' => 'mixed']]],
			'single wins over catalogi' => [['params' => ['_catalog' => 'open', '_catalogi' => ['woo']]]],
			'empty single falls to catalogi' => [['params' => ['_catalog' => '', '_catalogi' => ['woo']]]],
			'non-string single falls to catalogi' => [['params' => ['_catalog' => 5, '_catalogi' => ['open']]]],
			'catalogi union' => [['params' => ['_catalogi' => ['woo', 'open', 'mixed']]]],
			'catalogi with a named scope' => [['params' => ['_catalogi' => ['named', 'woo']]]],
			'catalogi skips bad entries' => [['params' => ['_catalogi' => ['', 7, null, 'nope', 'draft', 'later', 'woo', 'woo']]]],
			'catalogi only bad entries' => [['params' => ['_catalogi' => ['draft', 'nope']]]],
			'catalogi as a string' => [['params' => ['_catalogi' => 'woo']]],
			'empty catalogi is the default' => [['params' => ['_catalogi' => []]]],
			'default union' => [['params' => []]],
			'default without catalog config' => [['params' => [], 'config' => ['catalog_schema' => '']]],
			'default without object service' => [['params' => [], 'noObjectService' => true]],
			'catalogi service missing' => [['params' => ['_catalog' => 'woo'], 'containerFails' => true]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScopeMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->resolve(case: $case);

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

	}//end testScopeMatchesTheRecording()

	/**
	 * Build the service over a scripted CatalogiService and resolve the scope.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The scope, the lookups and the log.
	 */
	private function resolve(array $case): array {
		$log = [];
		$catalogi = new class(self::catalogs(), ($case['noObjectService'] ?? false)) {
			/**
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<string, array<string, mixed>> $catalogs The catalogues by slug.
			 * @param bool $noObjectService Whether OpenRegister is missing.
			 */
			public function __construct(private array $catalogs, private bool $noObjectService) {
			}

			/**
			 * @param string $slug The slug.
			 *
			 * @return array<string, mixed>|null
			 */
			public function getCatalogBySlug(string $slug): ?array {
				$this->calls[] = ['getCatalogBySlug', $slug];
				return ($this->catalogs[$slug] ?? null);
			}

			/**
			 * @return object|null
			 */
			public function getObjectService(): ?object {
				$this->calls[] = ['getObjectService'];
				if ($this->noObjectService === true) {
					return null;
				}

				$calls = &$this->calls;
				$catalogs = array_values($this->catalogs);
				return new class($catalogs, $calls) {
					/**
					 * @param list<array<string, mixed>> $catalogs The stored catalogues.
					 * @param array<int, mixed> $calls The shared call log.
					 */
					public function __construct(private array $catalogs, private array &$calls) {
					}

					/**
					 * @param array<string, mixed> $query The query.
					 * @param bool $_rbac Whether RBAC applies.
					 * @param bool $_multitenancy Whether multitenancy applies.
					 *
					 * @return list<array<string, mixed>>
					 */
					public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
						$this->calls[] = ['searchObjects', $query, $_rbac, $_multitenancy];
						return $this->catalogs;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($catalogi, $case): object {
				if (($case['containerFails'] ?? false) === true) {
					throw new RuntimeException('no ' . $id);
				}

				return $catalogi;
			}
		);

		$values = array_merge(['catalog_register' => '5', 'catalog_schema' => '6'], ($case['config'] ?? []));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$log, $level): void {
					$log[] = [$level, $message, $context];
				}
			);
		}

		$service = new PublicationQueryService(container: $container, config: $config, logger: $logger);
		$method = new ReflectionMethod(PublicationQueryService::class, 'resolveCatalogScope');
		$scope = $method->invoke($service, $case['params']);

		return ['scope' => $scope, 'calls' => $catalogi->calls, 'log' => $log];

	}//end resolve()
}//end class
