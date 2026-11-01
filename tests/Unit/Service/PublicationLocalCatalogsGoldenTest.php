<?php

/**
 * Golden test for the local catalogs and the federated "used" lookup.
 *
 * Runs `PublicationService::getLocalCatalogs()` twice per case over scripted
 * config and catalog searches, and `getFederatedUsed()` / `getFederatedUses()`
 * over a scripted local answer and directory service, and compares the
 * results and the collaborator calls with the recording.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/PublicationLocalCatalogsGoldenTest.php`.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for PublicationService::getLocalCatalogs() and getFederatedUsed().
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */
class PublicationLocalCatalogsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-local-catalogs-golden.json';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The local-catalog cases.
	 *
	 * Each catalog is `[shape, data]`: `entity` wraps the data in an
	 * ObjectEntity, `array` passes it as is.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function catalogCases(): array {
		$catalogs = [
			['entity', ['id' => 'c1', 'slug' => 'woo', 'title' => 'Woo', 'summary' => 'S', 'description' => 'D']
				+ ['organization' => 'O', 'listed' => true, 'extra' => 1]],
			['array', ['id' => 'c2', '@self' => ['slug' => 'from-self']]],
			['entity', ['id' => 'c3', 'slug' => 'own', '@self' => ['slug' => 'ignored'], 'listed' => 0]],
			['array', ['title' => 'No id']],
			['entity', ['id' => '0', 'title' => 'Zero id']],
			['array', ['id' => '', 'title' => 'Empty id']],
			['entity', ['id' => 7, 'title' => null, 'summary' => null, 'description' => null]],
		];

		return [
			'catalogs' => [['catalogs' => $catalogs]],
			'no catalogs' => [['catalogs' => []]],
			'only catalogs without id' => [['catalogs' => [$catalogs[3], $catalogs[4]]]],
			'no catalog schema' => [['catalogs' => $catalogs, 'schema' => '']],
			'no catalog register' => [['catalogs' => $catalogs, 'register' => '']],
			'catalog schema zero' => [['catalogs' => $catalogs, 'schema' => '0']],
			'search fails' => [['catalogs' => $catalogs, 'searchFails' => true]],
			'openregister absent' => [['catalogs' => $catalogs, 'apps' => []]],
			'config fails' => [['catalogs' => $catalogs, 'configFails' => true]],
		];

	}//end catalogCases()

	/**
	 * The federated "used" cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function usedCases(): array {
		$local = ['results' => [['id' => 'l1']], 'total' => 1];
		$peer = ['results' => [['id' => 'f1'], ['id' => 'f2']], 'sources' => ['https://peer' => 'Peer A']];

		return [
			'merged' => [['local' => $local, 'peer' => $peer]],
			'aggregation off' => [['local' => $local, 'peer' => $peer, 'params' => ['_aggregate' => 'false']]],
			'aggregation zero' => [['local' => $local, 'peer' => $peer, 'params' => ['_aggregate' => '0']]],
			'aggregation other' => [['local' => $local, 'peer' => $peer, 'params' => ['_aggregate' => 'no']]],
			'timeouts in bounds' => [['local' => $local, 'peer' => $peer, 'params' => ['timeout' => '30', 'connect_timeout' => 5, '_extend' => 'x']]],
			'timeouts at the bounds' => [['local' => $local, 'peer' => $peer, 'params' => ['timeout' => 120, 'connect_timeout' => '30']]],
			'timeouts out of bounds' => [['local' => $local, 'peer' => $peer, 'params' => ['timeout' => 121, 'connect_timeout' => 31]]],
			'timeouts zero or negative' => [['local' => $local, 'peer' => $peer, 'params' => ['timeout' => '0', 'connect_timeout' => '-1']]],
			'connect timeout zero' => [['local' => $local, 'peer' => $peer, 'params' => ['timeout' => 1, 'connect_timeout' => 0]]],
			'local without results' => [['local' => ['error' => 'Not Found'], 'peer' => $peer]],
			'peer without results or sources' => [['local' => $local, 'peer' => []]],
			'peer fails' => [['local' => $local, 'peer' => 'throw']],
		];

	}//end usedCases()

	/**
	 * Read the local catalogs twice and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('catalogCases')]
	public function testLocalCatalogsMatchTheRecording(array $case): void {
		$this->calls = [];
		$service = $this->service(case: $case);
		$method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');

		$results = [];
		foreach ([1, 2] as $_round) {
			try {
				$results[] = ['result' => $method->invoke($service)];
			} catch (\Throwable $e) {
				$results[] = ['exception' => get_class($e), 'message' => $e->getMessage()];
			}
		}

		$this->assertMatchesRecording(actual: ['results' => $results, 'calls' => $this->calls]);

	}//end testLocalCatalogsMatchTheRecording()

	/**
	 * Run the federated "used" and "uses" lookups and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('usedCases')]
	public function testFederatedUsedMatchesTheRecording(array $case): void {
		$this->calls = [];
		$service = $this->service(case: $case);
		$params = ($case['params'] ?? []);

		$actual = [
			'used' => $service->getFederatedUsed(id: 'pub-1', queryParams: $params),
			'uses' => $service->getFederatedUses(id: 'pub-1', queryParams: $params),
		];

		$this->assertMatchesRecording(actual: ['results' => $actual, 'calls' => $this->calls]);

	}//end testFederatedUsedMatchesTheRecording()

	/**
	 * Compare with, or write, the recording of the current data set.
	 *
	 * @param array<string, mixed> $actual What the case produced.
	 *
	 * @return void
	 */
	private function assertMatchesRecording(array $actual): void {
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = $this->name() . ' ' . (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey(key: $name, array: $recorded, message: 'No recording for ' . $name);
		$this->assertSame(
			expected: json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			actual: json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end assertMatchesRecording()

	/**
	 * Build the service over the case's scripted collaborators.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return PublicationService
	 */
	private function service(array $case): PublicationService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = []) use ($case): array {
				$this->calls[] = ['searchObjects' => ['query' => $query]];
				if (($case['searchFails'] ?? false) === true) {
					throw new \RuntimeException('search down');
				}

				return array_map(fn (array $spec): mixed => $this->catalog(spec: $spec), ($case['catalogs'] ?? []));
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->calls[] = ['getValueString' => ['app' => $app, 'key' => $key]];
				if (($case['configFails'] ?? false) === true) {
					throw new \RuntimeException('config down');
				}

				return match ($key) {
					'catalog_register' => ($case['register'] ?? 'catalog-register'),
					'catalog_schema' => ($case['schema'] ?? 'catalog-schema'),
					default => $default,
				};
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));

		$directoryService = $this->createMock(originalClassName: DirectoryService::class);
		$directoryService->method('getUsed')->willReturnCallback(
			function (string $uuid, array $guzzleConfig = []) use ($case): array {
				$this->calls[] = ['getUsed' => ['uuid' => $uuid, 'guzzleConfig' => $guzzleConfig]];
				if (($case['peer'] ?? null) === 'throw') {
					throw new \RuntimeException('peer down');
				}

				return ($case['peer'] ?? []);
			}
		);

		$service = $this->getMockBuilder(className: PublicationService::class)
			->setConstructorArgs(
				[
					$config,
					$this->createMock(originalClassName: IRequest::class),
					$container,
					$appManager,
					$directoryService,
					$this->createMock(originalClassName: DownloadService::class),
					$this->createMock(originalClassName: IUserSession::class),
				]
			)
			->onlyMethods(['used', 'uses'])
			->getMock();
		foreach (['used', 'uses'] as $lookup) {
			$service->method($lookup)->willReturnCallback(
				function (string $id) use ($case, $lookup): JSONResponse {
					$this->calls[] = [$lookup => ['id' => $id]];
					return new JSONResponse(($case['local'] ?? []));
				}
			);
		}

		return $service;

	}//end service()

	/**
	 * Build one stored catalog in the shape its spec names.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $spec The shape and the data.
	 *
	 * @return mixed The catalog.
	 */
	private function catalog(array $spec): mixed {
		[$shape, $data] = $spec;
		if ($shape === 'array') {
			return $data;
		}

		$object = $this->getMockBuilder(className: ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$object->method('jsonSerialize')->willReturn($data);
		return $object;

	}//end catalog()
}//end class
