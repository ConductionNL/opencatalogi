<?php

/**
 * Golden test for the listings this instance serves and advertises.
 *
 * Runs DirectoryService::convertCatalogToListing() over catalog shapes and
 * DirectoryService::directoryListings() over stored listings and request
 * parameters, and compares the results and the collaborator calls with the
 * recording. A `published` value of "now" is recorded as `(now)`.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/DirectoryListingShapeGoldenTest.php`.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for DirectoryService::convertCatalogToListing() and directoryListings().
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class DirectoryListingShapeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/directory-listing-shape-golden.json';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The catalogs to convert, each `[shape, data]`: `entity` wraps the data in
	 * an ObjectEntity, `array` passes it as is.
	 *
	 * @return array<string, array{0: array{0: string, 1: array<string, mixed>}}>
	 */
	public static function catalogs(): array {
		$full = [
			'id' => 'c1', 'title' => 'Woo', 'summary' => 'S', 'description' => 'D', 'status' => 'live',
			'organization' => 'O', 'schemas' => ['s1'], 'published' => '2026-01-01T00:00:00+00:00', 'slug' => 'woo',
		];

		return [
			'full entity' => [['entity', $full]],
			'full array' => [['array', $full]],
			'wrapped under object' => [['array', ['id' => 'outer', 'object' => ['title' => 'Inner', 'published' => 'p']]]],
			'wrapped with own id' => [['entity', ['id' => 'outer', 'object' => ['id' => 'inner', 'published' => 'p']]]],
			'empty' => [['array', []]],
			'summary from description' => [['array', ['id' => 'c', 'description' => 'D', 'published' => 'p']]],
			'dcat on' => [['array', ['id' => 'c', 'slug' => 'woo', 'hasDcat' => true, 'published' => 'p']]],
			'dcat as text' => [['array', ['id' => 'c', 'slug' => 'woo', 'hasDcat' => 'yes', 'published' => 'p']]],
			'dcat off' => [['array', ['id' => 'c', 'slug' => 'woo', 'hasDcat' => 'false', 'published' => 'p']]],
			'dcat without slug' => [['array', ['id' => 'c', 'hasDcat' => true, 'hasOoapi' => true, 'published' => 'p']]],
			'ooapi on' => [['array', ['id' => 'c', 'slug' => 'edu', 'hasOoapi' => '1', 'published' => 'p']]],
			'dcat and ooapi' => [['entity', ['id' => 'c', 'slug' => 'both', 'hasDcat' => 1, 'hasOoapi' => 'on', 'published' => 'p']]],
		];

	}//end catalogs()

	/**
	 * The directory-listing worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$self = 'https://self/route/opencatalogi.directory.index';
		$stored = [
			['entity', ['id' => 'l1', 'directory' => $self, 'title' => 'Ours', 'status' => 'x', 'metadata' => [], 'listed' => true]],
			['array', ['id' => 'l2', 'directory' => 'https://peer/api/directory', 'default' => true]],
			['array', ['object' => ['id' => 'l3', 'directory' => 'https://peer/api/directory', 'image' => 'i'], 'id' => 'w3']],
			['entity', ['object' => ['id' => 'l4', 'directory' => $self, 'filters' => []]]],
			['array', ['id' => 'l5', 'directory' => '', 'lastSync' => 'y']],
			['array', ['id' => 'l6', 'available' => true, 'statusCode' => 200]],
		];

		return [
			'local only' => [['stored' => $stored]],
			'include federated' => [['stored' => $stored, 'params' => ['include-federated' => '1']]],
			'include federated false' => [['stored' => $stored, 'params' => ['include-federated' => 'false']]],
			'include federated text' => [['stored' => $stored, 'params' => ['include-federated' => 'yes']]],
			'paging and filters' => [
				['stored' => $stored, 'params' => ['limit' => '5', 'offset' => 2, 'filters' => ['schema' => 'x', 'register' => 'y', 'title' => 'Ours']]],
			],
			'no listings' => [['stored' => []]],
			'no listing schema' => [['stored' => $stored, 'schema' => '']],
			'no listing register' => [['stored' => $stored, 'register' => '']],
			'search fails' => [['stored' => $stored, 'searchFails' => true]],
		];

	}//end worlds()

	/**
	 * Convert one catalog and compare with the recording.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $spec The catalog.
	 *
	 * @return void
	 */
	#[DataProvider('catalogs')]
	public function testCatalogListingMatchesTheRecording(array $spec): void {
		$this->calls = [];
		$service = $this->service(world: []);
		$method = new \ReflectionMethod(DirectoryService::class, 'convertCatalogToListing');

		$listing = $method->invoke($service, $this->stored(spec: $spec));
		$this->assertMatchesRecording(actual: ['listing' => self::withNow(listing: $listing), 'calls' => $this->calls]);

	}//end testCatalogListingMatchesTheRecording()

	/**
	 * Serve the directory listings of one world and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testDirectoryListingsMatchTheRecording(array $world): void {
		$this->calls = [];
		$service = $this->service(world: $world);
		$method = new \ReflectionMethod(DirectoryService::class, 'directoryListings');

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = []) use ($world): array {
				$this->calls[] = ['searchObjects' => ['query' => $query]];
				if (($world['searchFails'] ?? false) === true) {
					throw new \RuntimeException('search down');
				}

				return array_map(fn (array $spec): mixed => $this->stored(spec: $spec), $world['stored']);
			}
		);

		$listings = $method->invoke($service, $objectService, ($world['params'] ?? []));
		$this->assertMatchesRecording(actual: ['listings' => $listings, 'calls' => $this->calls]);

	}//end testDirectoryListingsMatchTheRecording()

	/**
	 * Replace a `published` value of "now" with `(now)`.
	 *
	 * @param array<string, mixed> $listing The listing.
	 *
	 * @return array<string, mixed> The listing, stable across runs.
	 */
	private static function withNow(array $listing): array {
		$published = strtotime((string)($listing['published'] ?? ''));
		if ($published !== false && abs($published - time()) < 60) {
			$listing['published'] = '(now)';
		}

		return $listing;

	}//end withNow()

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
	 * Build the service over scripted collaborators.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DirectoryService
	 */
	private function service(array $world): DirectoryService {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			function (string $routeName, array $arguments = []): string {
				$this->calls[] = ['linkToRoute' => ['routeName' => $routeName]];
				return '/route/' . $routeName;
			}
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			function (string $url): string {
				$this->calls[] = ['getAbsoluteURL' => ['url' => $url]];
				return 'https://self' . $url;
			}
		);
		$urls->method('getBaseUrl')->willReturnCallback(
			function (): string {
				$this->calls[] = ['getBaseUrl' => []];
				return 'https://self/';
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($world): string {
				$this->calls[] = ['getValueString' => ['app' => $app, 'key' => $key]];
				return match ($key) {
					'listing_schema' => ($world['schema'] ?? 'listing-schema'),
					'listing_register' => ($world['register'] ?? 'listing-register'),
					default => $default,
				};
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppInfo')->willReturnCallback(
			function (string $appId): array {
				$this->calls[] = ['getAppInfo' => ['appId' => $appId]];
				return ['version' => '1.2.3'];
			}
		);

		return new DirectoryService(
			$urls,
			$config,
			$this->createMock(originalClassName: ContainerInterface::class),
			$appManager,
			$this->createMock(originalClassName: BroadcastService::class),
			$this->createMock(originalClassName: IRequest::class),
		);

	}//end service()

	/**
	 * Build one stored object in the shape its spec names.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $spec The shape and the data.
	 *
	 * @return mixed The object.
	 */
	private function stored(array $spec): mixed {
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

	}//end stored()
}//end class
