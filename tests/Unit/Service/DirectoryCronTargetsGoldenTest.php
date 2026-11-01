<?php

/**
 * Golden test for the directory sync targets and the cron sync tally.
 *
 * Runs DirectoryService::getKnownDirectoryUrls() over stored listings and
 * self-instance settings, and DirectoryService::doCronSync() over those
 * targets with a scripted syncDirectory(), and compares the results and every
 * collaborator call (by parameter name) with the recording.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/DirectoryCronTargetsGoldenTest.php`.
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
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for DirectoryService::getKnownDirectoryUrls() and doCronSync().
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class DirectoryCronTargetsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/directory-cron-targets-golden.json';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The worlds. Each listing is `[shape, data]`: `wrapped` puts the data
	 * under `object`, `flat` serialises to the data itself.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$listings = [
			['flat', ['directory' => 'https://a.example/apps/opencatalogi/api/directory']],
			['wrapped', ['directory' => 'https://b.example/api/directory']],
			['flat', ['directory' => 'https://a.example/apps/opencatalogi/api/directory']],
			['flat', ['directory' => 'https://c.example/api/publications']],
			['flat', ['directory' => '']],
			['wrapped', ['title' => 'no directory']],
			['flat', ['directory' => '0']],
			['flat', ['directory' => 'https://self.example/apps/opencatalogi/api/directory']],
			['flat', ['directory' => 'https://alias.example:8443/api/directory']],
			['flat', ['directory' => 'https://alias.example:9443/api/directory']],
			['flat', ['directory' => 'https://SELF.example/x/api/directory?y=1']],
		];

		return [
			'listings' => [['listings' => $listings]],
			'with aliases' => [['listings' => $listings, 'aliases' => ' alias.example:8443 , ,other.example']],
			'alias without port' => [['listings' => $listings, 'aliases' => 'alias.example']],
			'no listings' => [['listings' => []]],
			'default already known' => [['listings' => $listings, 'default' => 'https://b.example/api/directory']],
			'no listing schema' => [['listings' => $listings, 'schema' => '']],
			'no listing register' => [['listings' => $listings, 'register' => '']],
			'search fails' => [['listings' => 'throw']],
			'openregister absent' => [['listings' => $listings, 'apps' => []]],
			'every sync fails' => [['listings' => $listings, 'syncFails' => true]],
			'no reporter' => [['listings' => [$listings[1]], 'reporter' => false]],
		];

	}//end worlds()

	/**
	 * Read the known directories, run the cron sync, and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testTargetsAndTallyMatchTheRecording(array $world): void {
		$actual = [];
		foreach (['getKnownDirectoryUrls', 'doCronSync'] as $method) {
			$this->calls = [];
			try {
				$outcome = ['result' => $this->service(world: $world)->$method()];
			} catch (\Throwable $e) {
				$outcome = ['exception' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$method] = $outcome + ['calls' => $this->calls];
		}

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

	}//end testTargetsAndTallyMatchTheRecording()

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
	 * Build the service around the world, with syncDirectory() scripted.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DirectoryService
	 */
	private function service(array $world): DirectoryService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (...$args) use ($world): array {
				$this->record(method: 'searchObjects', args: $args);
				if ($world['listings'] === 'throw') {
					throw new \RuntimeException('search down');
				}

				return array_map(static fn (array $spec): object => self::listing(spec: $spec), $world['listings']);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($world['apps'] ?? ['openregister']));

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($world): string {
				$this->calls[] = ['IAppConfig::getValueString' => ['key' => $key]];
				return match ($key) {
					'listing_schema' => ($world['schema'] ?? 'listing-schema'),
					'listing_register' => ($world['register'] ?? 'listing-register'),
					'instance_aliases' => ($world['aliases'] ?? ''),
					'default_directory_url' => ($world['default'] ?? 'https://directory.example/api/directory'),
					default => $default,
				};
			}
		);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://self.example');

		$reporter = null;
		if (($world['reporter'] ?? true) === true) {
			$reporter = $this->createMock(originalClassName: ConnectionReporter::class);
			$reporter->method('reportDirectorySync')->willReturnCallback(
				function (array $results): bool {
					$this->calls[] = ['ConnectionReporter::reportDirectorySync' => ['results' => $results]];
					return true;
				}
			);
		}

		$service = $this->getMockBuilder(className: DirectoryService::class)
			->setConstructorArgs(
				[
					$urls,
					$config,
					$container,
					$appManager,
					$this->createMock(originalClassName: BroadcastService::class),
					$this->createMock(originalClassName: IRequest::class),
					$this->createMock(originalClassName: LoggerInterface::class),
					$reporter,
				]
			)
			->onlyMethods(['syncDirectory'])
			->getMock();
		$service->method('syncDirectory')->willReturnCallback(
			function (string $directoryUrl) use ($world): array {
				$this->calls[] = ['syncDirectory' => ['directoryUrl' => $directoryUrl]];
				if (($world['syncFails'] ?? false) === true || str_contains($directoryUrl, 'b.example') === true) {
					throw new \RuntimeException('cannot sync ' . $directoryUrl);
				}

				return ['listings_created' => 1];
			}
		);

		return $service;

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

		return new class($data) {
			/**
			 * @param array<string, mixed> $data The serialised listing.
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

	}//end listing()
}//end class
