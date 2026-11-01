<?php

/**
 * Golden test for DirectoryService::getUniqueDirectories.
 *
 * Every world stores a set of listings and records the publications URLs the
 * service returns for a sequence of calls, and the searches it ran.
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

use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for DirectoryService::getUniqueDirectories.
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class DirectoryUniqueGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/directory-unique-golden.json';

	/**
	 * The searches the world ran.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * The recorded worlds.
	 *
	 * Each listing is `[shape, data]`: `wrapped` puts the data under `object`,
	 * `flat` serialises to the data itself, `throws` throws on serialise.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$listings = [
			['wrapped', ['publications' => 'https://a/pub', 'integrationLevel' => 'search', 'default' => true]],
			['flat', ['publications' => 'https://b/pub', 'integrationLevel' => 'search', 'default' => false]],
			['wrapped', ['publications' => 'https://c/pub', 'integrationLevel' => 'none', 'default' => true]],
			['flat', ['publications' => 'https://d/pub']],
			['wrapped', ['publications' => 'https://a/pub', 'integrationLevel' => 'search', 'default' => true]],
			['flat', ['publications' => '', 'integrationLevel' => 'search', 'default' => true]],
			['wrapped', ['integrationLevel' => 'search', 'default' => true]],
			['flat', ['publications' => 'https://e/pub', 'integrationLevel' => 'search', 'default' => 0]],
			['flat', ['publications' => 'https://f/pub', 'integrationLevel' => 'search', 'default' => null]],
		];

		return [
			'all' => [['listings' => $listings, 'calls' => [[false, false]]]],
			'available only' => [['listings' => $listings, 'calls' => [[true, false]]]],
			'default only' => [['listings' => $listings, 'calls' => [[false, true]]]],
			'available and default' => [['listings' => $listings, 'calls' => [[true, true]]]],
			'cached across flags' => [['listings' => $listings, 'calls' => [[true, true], [false, false]]]],
			'no listings' => [['listings' => [], 'calls' => [[false, false]]]],
			'no listing schema' => [['listings' => $listings, 'calls' => [[false, false]], 'schema' => '']],
			'no listing register' => [['listings' => $listings, 'calls' => [[false, false]], 'register' => '']],
			'serialise fails halfway' => [['listings' => [$listings[0], $listings[1], ['throws', []], $listings[3]], 'calls' => [[false, false]]]],
			'search fails' => [['listings' => $listings, 'calls' => [[false, false]], 'searchFails' => true]],
			'openregister absent' => [['listings' => $listings, 'calls' => [[false, false]], 'apps' => []]],
		];

	}//end worlds()

	/**
	 * Run the calls in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testUniqueDirectoriesMatchTheRecording(array $world): void {
		$this->searches = [];
		$service = $this->service(world: $world);

		$results = [];
		foreach ($world['calls'] as [$availableOnly, $defaultOnly]) {
			try {
				$results[] = ['result' => $service->getUniqueDirectories(availableOnly: $availableOnly, defaultOnly: $defaultOnly)];
			} catch (\Throwable $e) {
				$results[] = ['exception' => get_class($e), 'message' => $e->getMessage()];
			}
		}

		$actual = ['results' => $results, 'searches' => $this->searches];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testUniqueDirectoriesMatchTheRecording()

	/**
	 * Build the service around the world's listings.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DirectoryService
	 */
	private function service(array $world): DirectoryService {
		$listings = array_map(static fn (array $spec): object => self::listing(spec: $spec), $world['listings']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($listings, $world): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if (($world['searchFails'] ?? false) === true) {
					throw new \RuntimeException('search down');
				}

				return $listings;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($world['apps'] ?? ['openregister']));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnMap([
			['opencatalogi', 'listing_schema', '', ($world['schema'] ?? 'listing-schema')],
			['opencatalogi', 'listing_register', '', ($world['register'] ?? 'listing-register')],
		]);

		return new DirectoryService(
			$this->createMock(IURLGenerator::class),
			$config,
			$container,
			$appManager,
			$this->createMock(BroadcastService::class),
			$this->createMock(IRequest::class),
		);

	}//end service()

	/**
	 * Build one stored listing in the shape its spec names.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $spec The shape and the data.
	 *
	 * @return object The listing.
	 */
	private static function listing(array $spec): object {
		[$shape, $data] = $spec;
		if ($shape === 'wrapped') {
			$data = ['id' => 'x', 'object' => $data];
		}

		return new class($data, $shape === 'throws') implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $data   The serialised listing.
			 * @param bool                 $throws Whether serialising fails.
			 */
			public function __construct(private array $data, private bool $throws) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				if ($this->throws === true) {
					throw new \RuntimeException('cannot serialise');
				}

				return $this->data;
			}
		};

	}//end listing()
}//end class
