<?php

/**
 * Golden test for CatalogiService::getCatalogFilters().
 *
 * Runs the catalogue scope lookup over configured and unconfigured catalog
 * register/schema keys, catalog ids (none, slug, number, uuid) and stored
 * catalogs, and compares the result, the stored scope properties and every
 * collaborator call (by parameter name) with the recording. The schema-scope
 * read rule guard is scripted to drop `s-private`, so the recording shows that
 * its answer, not the raw list, becomes the scope. Every case starts from a
 * stale stored scope, so the recording shows what each path leaves behind.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/CatalogFiltersScopeGoldenTest.php`.
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

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CatalogiService::getCatalogFilters().
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogiService
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeResolver
 */
class CatalogFiltersScopeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-filters-scope-golden.json';

	private const UUID = '0d9e6f2c-3b1a-4c5d-8e7f-9a0b1c2d3e4f';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$catalogs = [
			['registers' => ['r1', 'r2'], 'schemas' => ['s1', 's-private']],
			['registers' => ['r2', 'r3'], 'schemas' => ['s1', 's2']],
			['registers' => 'not-a-list', 'schemas' => null],
			[],
		];

		return [
			'all catalogs' => [['catalogs' => $catalogs]],
			'by slug' => [['catalogs' => $catalogs, 'catalogId' => 'woo']],
			'by number' => [['catalogs' => $catalogs, 'catalogId' => 5]],
			'by uuid' => [['catalogs' => $catalogs, 'catalogId' => self::UUID]],
			'by upper-case uuid' => [['catalogs' => $catalogs, 'catalogId' => strtoupper(self::UUID)]],
			'empty slug' => [['catalogs' => $catalogs, 'catalogId' => '']],
			'no catalogs' => [['catalogs' => []]],
			'schema zero is configured' => [['catalogs' => $catalogs, 'schema' => '0', 'register' => '0']],
			'no catalog schema' => [['catalogs' => $catalogs, 'schema' => '']],
			'no catalog register' => [['catalogs' => $catalogs, 'register' => '', 'catalogId' => 'woo']],
			'nothing configured' => [['catalogs' => $catalogs, 'schema' => '', 'register' => '']],
			'search fails' => [['catalogs' => 'throw']],
			'openregister absent' => [['catalogs' => $catalogs, 'apps' => []]],
		];

	}//end cases()

	/**
	 * Look the scope up and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScopeMatchesTheRecording(array $case): void {
		$this->calls = [];
		$service = $this->service(case: $case);
		foreach (['availableRegisters', 'availableSchemas'] as $property) {
			(new \ReflectionProperty(CatalogiService::class, $property))->setValue($service, ['stale']);
		}

		try {
			$outcome = ['result' => $service->getCatalogFilters(catalogId: ($case['catalogId'] ?? null))];
		} catch (\Throwable $e) {
			$outcome = ['exception' => get_class($e), 'message' => $e->getMessage()];
		}

		$outcome['availableRegisters'] = $service->getAvailableRegisters();
		$outcome['availableSchemas'] = $service->getAvailableSchemas();
		$actual = ['outcome' => $outcome, 'calls' => $this->calls];

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey(key: $name, array: $recorded, message: 'No recording for ' . $name);
		$this->assertSame(
			expected: json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			actual: json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testScopeMatchesTheRecording()

	/**
	 * Record one ObjectService call by parameter name, without arguments left at their default.
	 *
	 * @param string            $method The ObjectService method.
	 * @param array<int, mixed> $args   The arguments the mock received.
	 *
	 * @return void
	 */
	private function record(string $method, array $args): void {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $args[$position] === $parameter->getDefaultValue()) {
				continue;
			}

			$named[$parameter->getName()] = $args[$position];
		}

		$this->calls[] = ['ObjectService::' . $method => $named];

	}//end record()

	/**
	 * Build the service over the case's scripted collaborators.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return CatalogiService
	 */
	private function service(array $case): CatalogiService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->record(method: 'searchObjects', args: $args);
				if ($case['catalogs'] === 'throw') {
					throw new \RuntimeException('search down');
				}

				return array_map(
					static fn (array $data): object => new class($data) {
						/**
						 * @param array<string, mixed> $data The catalog data.
						 */
						public function __construct(private array $data) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->data;
						}
					},
					$case['catalogs']
				);
			}
		);

		$queryService = $this->createMock(originalClassName: PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnCallback(
			function (array $schemaIds): array {
				$this->calls[] = ['PublicationQueryService::applySchemaScopeReadRuleGuard' => ['schemaIds' => $schemaIds]];
				return array_filter($schemaIds, static fn ($id): bool => $id !== 's-private');
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				PublicationQueryService::class => $queryService,
				default => $objectService,
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->calls[] = ['IAppConfig::getValueString' => ['app' => $app, 'key' => $key]];
				return match ($key) {
					'catalog_schema' => ($case['schema'] ?? 'catalog-schema'),
					'catalog_register' => ($case['register'] ?? 'catalog-register'),
					default => $default,
				};
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->calls[] = ['LoggerInterface::error' => ['message' => (string)$message, 'context' => $context]];
			}
		);

		return new CatalogiService(
			$config,
			$this->createMock(originalClassName: IRequest::class),
			$container,
			$appManager,
			$cacheFactory,
			$logger,
		);

	}//end service()
}//end class
