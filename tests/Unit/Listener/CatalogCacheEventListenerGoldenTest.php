<?php

/**
 * Golden test for CatalogCacheEventListener::handle().
 *
 * Every case replays one object event over recording doubles and compares every
 * config read, cache call and log line, in order, to the recording in
 * fixtures/catalog-cache-listener-golden.json.
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

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for CatalogCacheEventListener::handle().
 *
 * @covers \OCA\OpenCatalogi\Listener\CatalogCacheEventListener
 */
class CatalogCacheEventListenerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-cache-listener-golden.json';

	/**
	 * Everything asked and logged, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: event kind, object (schema, register, slug) and what throws.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'other event' => [['event' => 'other']],
			'created catalog' => [['event' => 'created']],
			'updated catalog' => [['event' => 'updated']],
			'deleted catalog' => [['event' => 'deleted']],
			'other schema' => [['event' => 'created', 'object' => ['schema' => 'x']]],
			'other register' => [['event' => 'deleted', 'object' => ['register' => 'x']]],
			'null schema' => [['event' => 'created', 'object' => ['schema' => null]]],
			'no slug on create' => [['event' => 'created', 'object' => ['slug' => null]]],
			'no slug on delete' => [['event' => 'deleted', 'object' => ['slug' => null]]],
			'config throws' => [['event' => 'created', 'throw' => 'config']],
			'warmup throws' => [['event' => 'updated', 'throw' => 'warmup']],
			'invalidate throws' => [['event' => 'deleted', 'throw' => 'invalidate']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandleMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$listener = new CatalogCacheEventListener($this->catalogs(case: $case), $this->config(case: $case), $this->logger());
		$listener->handle($this->event(case: $case));

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $this->log;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($this->log), true));

	}//end testHandleMatchesTheRecording()

	/**
	 * The event of a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return Event The event.
	 */
	private function event(array $case): Event {
		$object = $this->object(case: $case);

		return match ($case['event']) {
			'created' => new ObjectCreatedEvent($object),
			'updated' => new ObjectUpdatedEvent($object, null),
			'deleted' => new ObjectDeletedEvent($object),
			default => new Event(),
		};

	}//end event()

	/**
	 * The object of a case, recording each getter call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $case): ObjectEntity {
		$fields = (($case['object'] ?? []) + ['schema' => 's', 'register' => 'r', 'slug' => 'cat-1']);
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getUuid', 'getRegister', 'getSchema'])
			->getMock();
		$answers = [
			'getSchema' => $fields['schema'],
			'getRegister' => $fields['register'],
			'getUuid' => 'uuid-1',
			'jsonSerialize' => array_filter(['slug' => $fields['slug'], 'title' => 'T'], static fn ($v): bool => $v !== null),
		];
		foreach ($answers as $method => $answer) {
			$object->method($method)->willReturnCallback(
				function () use ($method, $answer): mixed {
					$this->log[] = ['object' => $method];
					return $answer;
				}
			);
		}

		return $object;
	}//end object()

	/**
	 * A config double answering the catalog schema `s` and register `r`.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return IAppConfig The double.
	 */
	private function config(array $case): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->log[] = ['config' => [$app, $key, $default]];
				if (($case['throw'] ?? null) === 'config') {
					throw new RuntimeException('config broken');
				}

				return ['catalog_schema' => 's', 'catalog_register' => 'r'][$key];
			}
		);

		return $config;
	}//end config()

	/**
	 * A catalogi-service double recording cache calls.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CatalogiServiceInterface The double.
	 */
	private function catalogs(array $case): CatalogiServiceInterface {
		$catalogs = $this->createMock(CatalogiServiceInterface::class);
		foreach (['warmup' => 'warmupCatalogCache', 'invalidate' => 'invalidateCatalogCache'] as $kind => $method) {
			$catalogs->method($method)->willReturnCallback(
				function (...$args) use ($case, $kind, $method): mixed {
					$this->log[] = [$method => $args];
					if (($case['throw'] ?? null) === $kind) {
						throw new RuntimeException($kind . ' broken');
					}

					return null;
				}
			);
		}

		return $catalogs;
	}//end catalogs()

	/**
	 * A logger double recording every line, with an exception reduced to its message.
	 *
	 * @return LoggerInterface The double.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					if (isset($context['exception']) === true) {
						$context['exception'] = get_class($context['exception']) . ': ' . $context['exception']->getMessage();
					}

					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
