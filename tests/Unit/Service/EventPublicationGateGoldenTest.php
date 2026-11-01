<?php

/**
 * Golden test for the auto-publish gate and the attachment publishing.
 *
 * Runs EventService::shouldAutoPublishObject() over object scopes, settings
 * and stored catalogs, and EventService::publishObjectAttachments() over
 * object lookups, files and share-link answers, and compares the results and
 * every collaborator call (by parameter name) with the recording.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/EventPublicationGateGoldenTest.php`.
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
 * @spec openspec/specs/auto-publishing/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for EventService::shouldAutoPublishObject() and publishObjectAttachments().
 *
 * @covers \OCA\OpenCatalogi\Service\EventService
 */
class EventPublicationGateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/event-publication-gate-golden.json';

	/**
	 * The calls the collaborators received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The auto-publish gate cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function gateCases(): array {
		$scope = ['@self' => ['register' => '5', 'schema' => '7']];
		$catalogs = [['registers' => [5], 'schemas' => [7, 8]]];

		return [
			'in a catalog' => [['object' => $scope, 'catalogs' => $catalogs]],
			'integer scope' => [['object' => ['@self' => ['register' => 5, 'schema' => 8]], 'catalogs' => $catalogs]],
			'register only in the catalog' => [['object' => ['@self' => ['register' => 5, 'schema' => 9]], 'catalogs' => $catalogs]],
			'schema only in the catalog' => [['object' => ['@self' => ['register' => 6, 'schema' => 7]], 'catalogs' => $catalogs]],
			'second catalog matches' => [['object' => $scope, 'catalogs' => [['registers' => [1], 'schemas' => [7]], ['registers' => [5], 'schemas' => [7]]]]],
			'catalog scope as text' => [['object' => $scope, 'catalogs' => [['registers' => ['5'], 'schemas' => ['7']]]]],
			'scope with a suffix' => [['object' => ['@self' => ['register' => '5abc', 'schema' => '7.9']], 'catalogs' => $catalogs]],
			'catalog without scope' => [['object' => $scope, 'catalogs' => [[]]]],
			'no catalogs' => [['object' => $scope, 'catalogs' => []]],
			'no self' => [['object' => ['title' => 'x'], 'catalogs' => $catalogs]],
			'no register' => [['object' => ['@self' => ['schema' => '7']], 'catalogs' => $catalogs]],
			'no schema' => [['object' => ['@self' => ['register' => '5']], 'catalogs' => $catalogs]],
			'null schema' => [['object' => ['@self' => ['register' => '5', 'schema' => null]], 'catalogs' => $catalogs]],
			'no catalog register setting' => [['object' => $scope, 'catalogs' => $catalogs, 'settings' => ['configuration' => ['catalog_schema' => 's']]]],
			'no catalog schema setting' => [['object' => $scope, 'catalogs' => $catalogs, 'settings' => ['configuration' => ['catalog_register' => 'r']]]],
			'no configuration' => [['object' => $scope, 'catalogs' => $catalogs, 'settings' => []]],
			'settings fail' => [['object' => $scope, 'catalogs' => $catalogs, 'settings' => 'throw']],
			'search fails' => [['object' => $scope, 'catalogs' => 'throw']],
			'openregister absent' => [['object' => $scope, 'catalogs' => $catalogs, 'apps' => []]],
		];

	}//end gateCases()

	/**
	 * The attachment publishing cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function attachmentCases(): array {
		$files = [
			['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
			['name' => 'shared.png', 'path' => 'files/shared.png', 'share_token' => 'tok'],
			['name' => 'missing.txt', 'path' => 'files/missing.txt'],
			['name' => 'gone.txt', 'path' => 'files/gone.txt'],
			['name' => 'empty.txt', 'path' => 'files/empty.txt', 'share_token' => null],
			['name' => 'boom.txt', 'path' => 'files/boom.txt'],
			['path' => 'files/anonymous.txt'],
			['path' => 'files/missing-anonymous.txt'],
			['name' => 'nopath.txt'],
			['name' => 'zero.txt', 'path' => 'files/zero.txt', 'share_token' => '0'],
		];

		return [
			'by uuid' => [['object' => ['@self' => ['uuid' => 'u-1', 'id' => 1]], 'files' => $files]],
			'by id' => [['object' => ['@self' => ['id' => 2]], 'files' => $files]],
			'null uuid falls back to id' => [['object' => ['@self' => ['uuid' => null, 'id' => 3]], 'files' => $files]],
			'no files' => [['object' => ['@self' => ['uuid' => 'u-1']], 'files' => []]],
			'object not found' => [['object' => ['@self' => ['uuid' => 'no-entity']], 'files' => $files]],
			'lookup fails' => [['object' => ['@self' => ['uuid' => 'find-throws']], 'files' => $files]],
			'file listing fails' => [['object' => ['@self' => ['uuid' => 'u-1']], 'files' => 'throw']],
			'openregister absent' => [['object' => ['@self' => ['uuid' => 'u-1']], 'files' => $files, 'apps' => []]],
		];

	}//end attachmentCases()

	/**
	 * Ask the gate and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('gateCases')]
	public function testGateMatchesTheRecording(array $case): void {
		$this->calls = [];
		$method = new \ReflectionMethod(EventService::class, 'shouldAutoPublishObject');

		$result = $method->invoke($this->service(case: $case), $case['object']);
		$this->assertMatchesRecording(actual: ['result' => $result, 'calls' => $this->calls]);

	}//end testGateMatchesTheRecording()

	/**
	 * Publish the attachments and compare with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('attachmentCases')]
	public function testAttachmentsMatchTheRecording(array $case): void {
		$this->calls = [];
		$method = new \ReflectionMethod(EventService::class, 'publishObjectAttachments');

		$result = $method->invoke($this->service(case: $case), $case['object']);
		$this->assertMatchesRecording(actual: ['result' => $result, 'calls' => $this->calls]);

	}//end testAttachmentsMatchTheRecording()

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
	 * Record one collaborator call by parameter name, without arguments left at their default.
	 *
	 * @param string            $call The class and method, as `Class::method`.
	 * @param array<int, mixed> $args The arguments the mock received.
	 *
	 * @return void
	 */
	private function record(string $call, array $args): void {
		$named = [];
		foreach ((new \ReflectionMethod($call))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $args[$position] === $parameter->getDefaultValue()) {
				continue;
			}

			$value = $args[$position];
			if (is_object($value) === true) {
				$value = get_class($value);
			}

			$named[$parameter->getName()] = $value;
		}

		$this->calls[] = [substr($call, (int)strrpos($call, '\\') + 1) => $named];

	}//end record()

	/**
	 * Build the service over the case's scripted collaborators.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return EventService
	 */
	private function service(array $case): EventService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSettings')->willReturnCallback(
			function () use ($case): array {
				$this->calls[] = ['SettingsService::getSettings' => []];
				$value = ($case['settings'] ?? ['configuration' => ['catalog_register' => 'cat-r', 'catalog_schema' => 'cat-s']]);
				if ($value === 'throw') {
					throw new \RuntimeException('settings unreadable');
				}

				return $value;
			}
		);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->record(call: ObjectService::class . '::searchObjects', args: $args);
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
		$objectService->method('find')->willReturnCallback(
			function (...$args): ?ObjectEntity {
				$this->record(call: ObjectService::class . '::find', args: $args);
				if ($args[0] === 'no-entity') {
					return null;
				}

				if ($args[0] === 'find-throws') {
					throw new \RuntimeException('lookup failed');
				}

				return new ObjectEntity();
			}
		);

		$fileMapper = $this->createMock(originalClassName: FileMapper::class);
		$fileMapper->method('getFilesForObject')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->record(call: FileMapper::class . '::getFilesForObject', args: $args);
				if (($case['files'] ?? []) === 'throw') {
					throw new \RuntimeException('files unreadable');
				}

				return ($case['files'] ?? []);
			}
		);

		$fileService = $this->createMock(originalClassName: FileService::class);
		$fileService->method('createShareLink')->willReturnCallback(
			function (...$args): string {
				$this->record(call: FileService::class . '::createShareLink', args: $args);
				$path = $args[0];
				return match (true) {
					str_contains($path, 'missing') => 'File not found',
					str_contains($path, 'gone') => "The file couldn't be found",
					str_contains($path, 'empty') => '',
					str_contains($path, 'boom') => throw new \RuntimeException('share refused'),
					default => 'https://example.com/s/' . basename($path),
				};
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $fileService,
				'OCA\OpenRegister\Db\FileMapper' => $fileMapper,
				default => $objectService,
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->calls[] = ['LoggerInterface::error' => ['message' => (string)$message, 'context' => array_keys($context)]];
			}
		);

		return new EventService($container, $appManager, $settings, $logger);

	}//end service()
}//end class
