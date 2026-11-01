<?php

/**
 * Golden test for PublicationService::getCatalogFilters().
 *
 * Runs a sequence of catalogue scope lookups on one service per case, over
 * configured and unconfigured catalog register/schema keys, catalog ids and
 * stored catalogs, and compares every result, the stored scope after each call
 * and every collaborator call (by parameter name) with the recording. The
 * sequence shows what the per-id cache keeps. The schema-scope read rule
 * guard is scripted to drop `s-private`.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/PublicationCatalogFiltersGoldenTest.php`.
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

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for PublicationService::getCatalogFilters().
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */
class PublicationCatalogFiltersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-catalog-filters-golden.json';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The cases: the stored catalogs, the config, and the sequence of catalog ids to look up.
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
			'all, cached' => [['catalogs' => $catalogs, 'ids' => [null, null]]],
			'one catalog, cached per id' => [['catalogs' => $catalogs, 'ids' => ['c1', 'c1', null, 'c1']]],
			'two catalogs are cached apart' => [['catalogs' => $catalogs, 'ids' => ['c1', 'c2', 'c1']]],
			'number and its text share the cache' => [['catalogs' => $catalogs, 'ids' => [5, '5']]],
			'no catalogs' => [['catalogs' => [], 'ids' => [null]]],
			'count-shaped search' => [['catalogs' => 'count', 'ids' => [null, 'c1']]],
			'no catalog schema is not cached' => [['catalogs' => $catalogs, 'schema' => '', 'ids' => [null, null]]],
			'no catalog register' => [['catalogs' => $catalogs, 'register' => '', 'ids' => ['c1']]],
			'schema zero is configured' => [['catalogs' => $catalogs, 'schema' => '0', 'register' => '0', 'ids' => [null]]],
			'scope after a refusal' => [['catalogs' => $catalogs, 'ids' => [null, 'c1'], 'refuseFrom' => 1]],
			'search fails' => [['catalogs' => 'throw', 'ids' => [null]]],
			'find fails' => [['catalogs' => $catalogs, 'ids' => ['missing']]],
			'openregister absent' => [['catalogs' => $catalogs, 'apps' => [], 'ids' => [null]]],
		];

	}//end cases()

	/**
	 * Run the sequence and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScopeMatchesTheRecording(array $case): void {
		$this->calls = [];
		$state = ['call' => 0];
		$service = $this->service(case: $case, state: $state);

		$steps = [];
		foreach ($case['ids'] as $index => $catalogId) {
			$state['call'] = $index;
			$this->calls[] = ['getCatalogFilters' => ['catalogId' => $catalogId]];
			try {
				$step = ['result' => $service->getCatalogFilters(catalogId: $catalogId)];
			} catch (\Throwable $e) {
				$step = ['exception' => get_class($e), 'message' => $e->getMessage()];
			}

			$step['availableRegisters'] = $service->getAvailableRegisters();
			$step['availableSchemas'] = $service->getAvailableSchemas();
			$steps[] = $step;
		}

		$actual = ['steps' => $steps, 'calls' => $this->calls];

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
	 * Build one stored catalog.
	 *
	 * @param array<string, mixed> $data The catalog data.
	 *
	 * @return object The catalog.
	 */
	private static function catalog(array $data): object {
		return new class($data) {
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
		};

	}//end catalog()

	/**
	 * Build the service over the case's scripted collaborators.
	 *
	 * @param array<string, mixed> $case  The case input.
	 * @param array{call: int}     $state The index of the call in progress.
	 *
	 * @return PublicationService
	 */
	private function service(array $case, array &$state): PublicationService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (...$args) use ($case): array|int {
				$this->record(method: 'searchObjects', args: $args);
				return match (true) {
					$case['catalogs'] === 'throw' => throw new \RuntimeException('search down'),
					$case['catalogs'] === 'count' => 2,
					default => array_map(static fn (array $data): object => self::catalog(data: $data), $case['catalogs']),
				};
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->record(method: 'find', args: $args);
				if ($args[0] === 'missing') {
					throw new \RuntimeException('not found');
				}

				$entity = $this->getMockBuilder(className: ObjectEntity::class)
					->disableOriginalConstructor()
					->onlyMethods(['jsonSerialize'])
					->getMock();
				$entity->method('jsonSerialize')->willReturn(['registers' => ['r-' . $args[0]], 'schemas' => ['s9', 's-private']]);
				return $entity;
			}
		);

		$queryService = $this->createMock(originalClassName: PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnCallback(
			function (array $schemaIds): array {
				$this->calls[] = ['applySchemaScopeReadRuleGuard' => ['schemaIds' => $schemaIds]];
				return array_filter($schemaIds, static fn ($id): bool => $id !== 's-private');
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				PublicationQueryService::class => $queryService,
				default => $objectService,
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case, &$state): string {
				$this->calls[] = ['IAppConfig::getValueString' => ['key' => $key]];
				if (isset($case['refuseFrom']) === true && $state['call'] >= $case['refuseFrom']) {
					return '';
				}

				return match ($key) {
					'catalog_schema' => ($case['schema'] ?? 'catalog-schema'),
					'catalog_register' => ($case['register'] ?? 'catalog-register'),
					default => $default,
				};
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));

		return new PublicationService(
			$config,
			$this->createMock(originalClassName: IRequest::class),
			$container,
			$appManager,
			$this->createMock(originalClassName: DirectoryService::class),
			$this->createMock(originalClassName: DownloadService::class),
			$this->createMock(originalClassName: IUserSession::class),
		);

	}//end service()
}//end class
