<?php

/**
 * Golden test for the single-publication lookup with federation.
 *
 * Runs `PublicationService::getFederatedPublication()` over a scripted local
 * lookup, local catalogs and directory service, and compares the result and
 * the request config sent to the directory with what was recorded: a local
 * hit with and without aggregation, local catalogs added or not, a miss that
 * stays local, the peer timeouts and their bounds, a peer hit, a peer miss and
 * a failing peer lookup.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/FederatedPublicationGoldenTest.php`.
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
 * Golden test for PublicationService::getFederatedPublication().
 */
class FederatedPublicationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/federated-publication-golden.json';

	/**
	 * The local lookup, local catalogs and peer answer of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$local = ['@self' => ['id' => 'p1', 'slug' => 'one'], 'title' => 'One'];
		$catalogs = [
			['id' => 'c1', 'slug' => 'woo', 'title' => 'Woo', 'listed' => true],
			['@self' => ['slug' => 'no-id']],
			['id' => 'c2', '@self' => ['slug' => 'from-self']],
		];
		$peer = ['result' => ['id' => 'f1', 'title' => 'Peer'], 'source' => ['name' => 'Peer A', 'url' => 'https://peer.a']];

		return [
			'local hit with local catalogs' => [['local' => [200, $local], 'catalogs' => $catalogs]],
			'local hit without local catalogs' => [['local' => [200, $local], 'catalogs' => []]],
			'local hit without self' => [['local' => [200, ['title' => 'Bare']], 'catalogs' => $catalogs]],
			'local hit, aggregation off' => [['local' => [200, $local], 'catalogs' => $catalogs, 'params' => ['_aggregate' => 'false']]],
			'local hit, aggregation zero' => [['local' => [200, $local], 'catalogs' => $catalogs, 'params' => ['_aggregate' => '0']]],
			'empty local answer goes to the peers' => [['local' => [200, []], 'peer' => $peer]],
			'local miss, aggregation off' => [['local' => [404, ['error' => 'nope']], 'params' => ['_aggregate' => 'false'], 'peer' => $peer]],
			'peer hit with timeouts' => [['local' => [404, []], 'params' => ['timeout' => '30', 'connect_timeout' => 5, '_extend' => 'x'], 'peer' => $peer]],
			'peer hit with timeouts at the bounds' => [['local' => [404, []], 'params' => ['timeout' => 120, 'connect_timeout' => '30'], 'peer' => ['result' => ['id' => 'f2']]]],
			'timeouts out of bounds' => [['local' => [404, []], 'params' => ['timeout' => 121, 'connect_timeout' => 31, '_aggregate' => 'yes'], 'peer' => $peer]],
			'timeouts zero or negative' => [['local' => [404, []], 'params' => ['timeout' => '0', 'connect_timeout' => '-1'], 'peer' => $peer]],
			'peer answer without result' => [['local' => [404, []], 'peer' => ['source' => ['name' => 'x']]]],
			'peer miss' => [['local' => [404, []], 'peer' => []]],
			'peer lookup fails' => [['local' => [500, ['error' => 'boom']], 'peer' => 'throw']],
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
	public function testLookupMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->lookup(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testLookupMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and look the publication up.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The result and the calls to the collaborators.
	 */
	private function lookup(array $case): array {
		$calls = [];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = []) use (&$calls, $case) {
				$calls[] = ['searchObjects' => $query];
				return array_map(
					function (array $data): ObjectEntity {
						$object = $this->getMockBuilder(ObjectEntity::class)
							->disableOriginalConstructor()
							->onlyMethods(['jsonSerialize'])
							->getMock();
						$object->method('jsonSerialize')->willReturn($data);
						return $object;
					},
					($case['catalogs'] ?? [])
				);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'catalog_register' => 'catalog-register',
				'catalog_schema' => 'catalog-schema',
				default => $default,
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$directoryService = $this->createMock(DirectoryService::class);
		$directoryService->method('getPublication')->willReturnCallback(
			static function (string $id, array $guzzleConfig = []) use (&$calls, $case) {
				$calls[] = ['getPublication' => $id, 'config' => $guzzleConfig];
				if (($case['peer'] ?? null) === 'throw') {
					throw new \RuntimeException('peer down');
				}

				return ($case['peer'] ?? null);
			}
		);

		$service = $this->getMockBuilder(PublicationService::class)
			->setConstructorArgs(
				[
					$config,
					$this->createMock(IRequest::class),
					$container,
					$appManager,
					$directoryService,
					$this->createMock(DownloadService::class),
					$this->createMock(IUserSession::class),
				]
			)
			->onlyMethods(['show'])
			->getMock();
		$service->method('show')->willReturnCallback(
			static function (string $id) use (&$calls, $case): JSONResponse {
				$calls[] = ['show' => $id];
				return new JSONResponse($case['local'][1], $case['local'][0]);
			}
		);

		$result = $service->getFederatedPublication('pub-1', ($case['params'] ?? []));

		return ['result' => $result, 'calls' => $calls];
	}//end lookup()
}//end class
