<?php

/**
 * Golden test for the object created and updated listeners.
 *
 * Drives both listeners' handle() over scripted options, objects and
 * EventService answers, and compares every call they make (the object data
 * handed to EventService and RetentionService, and every log line) with what
 * was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Listener/AutoPublishListenersGoldenTest.php`.
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
 * @covers \OCA\OpenCatalogi\Listener\ObjectCreatedEventListener
 * @covers \OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener
 * @covers \OCA\OpenCatalogi\Listener\ReportsAutoPublishOutcome
 * @covers \OCA\OpenCatalogi\Service\ChecksPublicationWindow
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\RetentionService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the auto-publish listeners.
 */
class AutoPublishListenersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/auto-publish-listeners-golden.json';

	/**
	 * Listener, options, objects and EventService answer per case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$live = ['publicationDate' => '2024-01-01T00:00:00+00:00', 'title' => 'Live'];
		$future = ['publicationDate' => '2999-01-01T00:00:00+00:00', 'title' => 'Future'];
		$depublished = ['publicationDate' => '2024-01-01T00:00:00+00:00', 'depublicationDate' => '2024-02-01T00:00:00+00:00'];
		$both = ['auto_publish_objects' => true, 'auto_publish_attachments' => true];
		$ok = ['processed' => 1, 'published' => 1, 'attachmentsPublished' => 2, 'errors' => []];
		$errors = ['processed' => 1, 'published' => 0, 'attachmentsPublished' => 0, 'errors' => ['first', 'second']];
		$nothing = ['processed' => 0, 'published' => 0, 'attachmentsPublished' => 0, 'errors' => []];
		$cases = [];
		foreach (['created', 'updated'] as $listener) {
			$cases += [
				$listener . ', other event' => [['listener' => $listener, 'otherEvent' => true]],
				$listener . ', options off' => [['listener' => $listener, 'options' => ['auto_publish_objects' => false, 'auto_publish_attachments' => false], 'new' => $live]],
				$listener . ', processed' => [['listener' => $listener, 'options' => $both, 'new' => $live, 'answer' => $ok]],
				$listener . ', errors' => [['listener' => $listener, 'options' => $both, 'new' => $live, 'answer' => $errors]],
				$listener . ', nothing processed' => [['listener' => $listener, 'options' => $both, 'new' => $live, 'answer' => $nothing]],
				$listener . ', event service throws' => [['listener' => $listener, 'options' => $both, 'new' => $live, 'throws' => true]],
				$listener . ', options unreadable' => [['listener' => $listener, 'options' => null, 'new' => $live]],
				$listener . ', future object' => [['listener' => $listener, 'options' => $both, 'new' => $future, 'answer' => $ok]],
				$listener . ', retention fails' => [['listener' => $listener, 'options' => $both, 'new' => $live, 'answer' => $ok, 'retentionThrows' => true]],
				$listener . ', no @self in data' => [['listener' => $listener, 'options' => $both, 'new' => ['@self' => ['x' => 1]] + $live, 'answer' => $ok]],
			];
		}

		$objectsOnly = ['auto_publish_objects' => true, 'auto_publish_attachments' => false];
		$attachmentsOnly = ['auto_publish_objects' => false, 'auto_publish_attachments' => true];
		$cases += [
			'updated, became live, objects only' => [['listener' => 'updated', 'options' => $objectsOnly, 'new' => $live, 'old' => $future, 'answer' => $ok]],
			'updated, stayed live, objects only' => [['listener' => 'updated', 'options' => $objectsOnly, 'new' => $live, 'old' => $live, 'answer' => $ok]],
			'updated, was depublished, objects only' => [['listener' => 'updated', 'options' => $objectsOnly, 'new' => $live, 'old' => $depublished, 'answer' => $ok]],
			'updated, no old object, objects only' => [['listener' => 'updated', 'options' => $objectsOnly, 'new' => $live, 'old' => null, 'answer' => $ok]],
			'updated, still future, objects only' => [['listener' => 'updated', 'options' => $objectsOnly, 'new' => $future, 'old' => $future, 'answer' => $ok]],
			'updated, depublished now, attachments only' => [['listener' => 'updated', 'options' => $attachmentsOnly, 'new' => $depublished, 'old' => $live, 'answer' => $ok]],
			'updated, live, attachments only' => [['listener' => 'updated', 'options' => $attachmentsOnly, 'new' => $live, 'old' => $live, 'answer' => $ok]],
		];
		return $cases;
	}//end cases()

	/**
	 * Every call the listener makes matches the recording.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testListenerMatchesTheRecording(array $case): void {
		$calls = [];
		$listener = $this->listener($case, $calls);
		$listener->handle($this->event($case));

		$name = $this->dataName();
		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $calls;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testListenerMatchesTheRecording()

	/**
	 * The event for a case.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return Event
	 */
	private function event(array $case): Event {
		if (($case['otherEvent'] ?? false) === true) {
			return $this->createMock(Event::class);
		}

		$new = $this->entity('new-uuid', $case['new']);
		if ($case['listener'] === 'created') {
			$event = $this->createMock(ObjectCreatedEvent::class);
			$event->method('getObject')->willReturn($new);
			return $event;
		}

		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getNewObject')->willReturn($new);
		$old = (array_key_exists('old', $case) === true ? $case['old'] : ['title' => 'Old']);
		$event->method('getOldObject')->willReturn($old === null ? null : $this->entity('new-uuid', $old));
		return $event;
	}//end event()

	/**
	 * An object entity serialising to the given data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The serialised data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getUuid', 'getRegister', 'getSchema'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getRegister')->willReturn('reg-1');
		$entity->method('getSchema')->willReturn('sch-1');
		return $entity;
	}//end entity()

	/**
	 * The listener over recording doubles.
	 *
	 * @param array<string, mixed> $case The scenario.
	 * @param array<int, mixed> $calls Every call, collected.
	 *
	 * @return ObjectCreatedEventListener|ObjectUpdatedEventListener
	 */
	private function listener(array $case, array &$calls): object {
		$settings = $this->createMock(SettingsService::class);
		if (array_key_exists('options', $case) === true && $case['options'] === null) {
			$settings->method('getPublishingOptions')->willThrowException(new \RuntimeException('options unreadable'));
		} else {
			$settings->method('getPublishingOptions')->willReturn($case['options'] ?? []);
		}

		$events = $this->createMock(EventService::class);
		$answer = static function (array $objects) use (&$calls, $case): array {
			$calls[] = ['events' => $objects];
			if (($case['throws'] ?? false) === true) {
				throw new \RuntimeException('event service down');
			}

			return $case['answer'];
		};
		$events->method('handleObjectCreateEvents')->willReturnCallback($answer);
		$events->method('handleObjectUpdateEvents')->willReturnCallback($answer);

		$retention = $this->createMock(RetentionService::class);
		$retention->method('applyDefaultsAtPublication')->willReturnCallback(
			static function (array $objectData) use (&$calls, $case): ?array {
				$calls[] = ['retention' => $objectData];
				if (($case['retentionThrows'] ?? false) === true) {
					throw new \RuntimeException('retention down');
				}

				return null;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function ($message, array $context = []) use (&$calls, $level): void {
					if (isset($context['exception']) === true) {
						$context['exception'] = get_class($context['exception']) . ': ' . $context['exception']->getMessage();
					}

					$calls[] = [$level => [(string)$message, $context]];
				}
			);
		}

		if ($case['listener'] === 'created') {
			return new ObjectCreatedEventListener($retention, $settings, $events, $logger);
		}

		return new ObjectUpdatedEventListener($retention, $settings, $events, $logger);
	}//end listener()
}//end class
