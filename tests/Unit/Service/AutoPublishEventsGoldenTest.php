<?php

/**
 * Golden test for `EventService::handleObjectCreateEvents()` and `handleObjectUpdateEvents()`.
 *
 * Runs both handlers over one scripted OpenRegister (a catalog that includes
 * register 5 / schema 7, a save that can fail, objects without an entity or
 * whose lookup throws, files with and without a share token and share links
 * that fail) under every combination of the two auto-publish options, and
 * compares the whole result, every save and every share link asked for with
 * what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/AutoPublishEventsGoldenTest.php`.
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
 *
 * @covers \OCA\OpenCatalogi\Service\EventService
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
use RuntimeException;

/**
 * Golden test for the auto-publish event handlers.
 */
class AutoPublishEventsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/auto-publish-events-golden.json';

	/**
	 * The objects every case runs over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function objects(): array {
		$past = '2024-01-15T10:00:00+00:00';
		return [
			['title' => 'match, live', 'publicationDate' => $past, '@self' => ['id' => 1, 'uuid' => 'u-1', 'register' => '5', 'schema' => '7']],
			['title' => 'fail-save', 'publicationDate' => $past, '@self' => ['id' => 2, 'uuid' => 'u-2', 'register' => 5, 'schema' => 7]],
			['title' => 'match, not live', '@self' => ['id' => 3, 'register' => 5, 'schema' => 7]],
			['title' => 'no match', 'publicationDate' => $past, '@self' => ['id' => 4, 'uuid' => 'u-4', 'register' => 9, 'schema' => 7]],
			['title' => 'no register', '@self' => ['id' => 5]],
			['title' => 'no id', '@self' => ['register' => 9, 'schema' => 9]],
			['title' => 'no entity', 'publicationDate' => $past, '@self' => ['id' => 6, 'uuid' => 'no-entity']],
			['title' => 'lookup throws', 'publicationDate' => $past, '@self' => ['id' => 7, 'uuid' => 'find-throws']],
			['title' => 'depublished', 'publicationDate' => $past, 'depublicationDate' => '2024-02-01T00:00:00+00:00', '@self' => ['id' => 8, 'uuid' => 'u-8']],
			['title' => 'id only', 'publicationDate' => $past, '@self' => ['id' => 9, 'register' => 5, 'schema' => 7]],
		];

	}//end objects()

	/**
	 * Event, publishing options and object list per case.
	 *
	 * @return array<string, array{0: string, 1: array<string, bool>|null, 2: bool}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['create', 'update'] as $event) {
			foreach ([[true, true], [true, false], [false, true], [false, false]] as [$objects, $attachments]) {
				$name = sprintf('%s, objects %s, attachments %s', $event, var_export($objects, true), var_export($attachments, true));
				$cases[$name] = [$event, ['auto_publish_objects' => $objects, 'auto_publish_attachments' => $attachments], true];
			}

			$cases[$event . ', no objects'] = [$event, ['auto_publish_objects' => true, 'auto_publish_attachments' => true], false];
			$cases[$event . ', options unreadable'] = [$event, null, true];
		}

		return $cases;
	}//end cases()

	/**
	 * Both handlers answer exactly as recorded.
	 *
	 * @param string $event 'create' or 'update'.
	 * @param array<string, bool>|null $options The publishing options, or null when reading them throws.
	 * @param bool $objects Whether to pass the object list.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandlersMatchTheRecording(string $event, ?array $options, bool $objects): void {
		$calls = [];
		$service = $this->service($options, $calls);
		$list = ($objects === true ? self::objects() : []);

		try {
			if ($event === 'create') {
				$result = $service->handleObjectCreateEvents($list);
			} else {
				$result = $service->handleObjectUpdateEvents($list);
			}
		} catch (RuntimeException $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['result' => $result, 'calls' => $calls];
		$name = $this->dataName();

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
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

	}//end testHandlersMatchTheRecording()

	/**
	 * An EventService over the scripted OpenRegister.
	 *
	 * @param array<string, bool>|null $options The publishing options, or null to throw.
	 * @param array<int, mixed> $calls Every save and share link, collected.
	 *
	 * @return EventService
	 */
	private function service(?array $options, array &$calls): EventService {
		$settings = $this->createMock(SettingsService::class);
		if ($options === null) {
			$settings->method('getPublishingOptions')->willThrowException(new \Exception('options unreadable'));
		} else {
			$settings->method('getPublishingOptions')->willReturn($options);
		}

		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => 'cat-r', 'catalog_schema' => 'cat-s']]);

		$catalog = new class {
			/**
			 * The catalog's scope.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['registers' => [5], 'schemas' => [7, 8]];
			}
		};

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturn([$catalog]);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$calls) {
				$object = ($args['object'] ?? $args[0]);
				unset($object['publicationDate']);
				$calls[] = ['save' => $object, 'uuid' => ($args['uuid'] ?? null), 'register' => ($args['register'] ?? null), 'schema' => ($args['schema'] ?? null)];
				if (($object['title'] ?? '') === 'fail-save') {
					throw new \Exception('save refused');
				}

				return new ObjectEntity();
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function ($id) {
				if ($id === 'no-entity') {
					return null;
				}

				if ($id === 'find-throws') {
					throw new \Exception('lookup failed');
				}

				return new ObjectEntity();
			}
		);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFilesForObject')->willReturn(
			[
				['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
				['name' => 'shared.png', 'path' => 'files/shared.png', 'share_token' => 'tok'],
				['name' => 'missing.txt', 'path' => 'files/missing.txt'],
				['name' => 'empty.txt', 'path' => 'files/empty.txt'],
				['name' => 'boom.txt', 'path' => 'files/boom.txt'],
			]
		);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('createShareLink')->willReturnCallback(
			function (string $path) use (&$calls): string {
				$calls[] = ['share' => $path];
				if (str_contains($path, 'missing') === true) {
					return 'File not found';
				}

				if (str_contains($path, 'empty') === true) {
					return '';
				}

				if (str_contains($path, 'boom') === true) {
					throw new \Exception('share refused');
				}

				return 'https://example.com/s/' . basename($path);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $fileService,
				'OCA\OpenRegister\Db\FileMapper' => $fileMapper,
				default => $objectService,
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		return new EventService($container, $appManager, $settings, $this->createMock(LoggerInterface::class));
	}//end service()
}//end class
