<?php

/**
 * Golden test for PortalAccountRemovedListener::handle().
 *
 * Every case hands one or more object updates to the listener and compares the
 * owner it cleans up after, the mapper lookups and the log lines to the recording
 * in fixtures/portal-account-removed-golden.json.
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

use OCA\OpenCatalogi\Contract\CitizenCollectionServiceInterface;
use OCA\OpenCatalogi\Listener\PortalAccountRemovedListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for PortalAccountRemovedListener::handle().
 *
 * @covers \OCA\OpenCatalogi\Listener\PortalAccountRemovedListener
 */
class PortalAccountRemovedGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/portal-account-removed-golden.json';

	/**
	 * The recorded cases.
	 *
	 * Each event is [new, old] with an object as [register, schema, data], old null
	 * for an update without an old object, or the string 'other' for another event.
	 * Keys besides events: maps (register and schema id-to-slug maps, or 'throw'),
	 * removeThrows.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$removed = ['subjectRef' => 'subject-1', 'status' => 'removed'];
		$active = ['subjectRef' => 'subject-1', 'status' => 'active'];
		$account = static fn (array $data): array => ['7', '41', $data];

		return [
			'another event' => [['events' => ['other']]],
			'no old object' => [['events' => [[$account($removed), null]]]],
			'removed now' => [['events' => [[$account($removed), $account($active)]]]],
			'already removed' => [['events' => [[$account($removed), $account($removed)]]]],
			'still active' => [['events' => [[$account($active), $account($active)]]]],
			'new without status' => [['events' => [[$account(['subjectRef' => 'subject-1']), $account($active)]]]],
			'old without status' => [['events' => [[$account($removed), $account(['subjectRef' => 'subject-1'])]]]],
			'status removed in capitals' => [['events' => [[$account(['subjectRef' => 'subject-1', 'status' => 'REMOVED']), $account($active)]]]],
			'subjectRef only on the old object' => [['events' => [[$account(['status' => 'removed']), $account(['subjectRef' => 'subject-old', 'status' => 'active'])]]]],
			'subjectRef empty on the new object' => [['events' => [[$account(['subjectRef' => '', 'status' => 'removed']), $account(['subjectRef' => 'subject-old', 'status' => 'active'])]]]],
			'subjectRef differs, new wins' => [['events' => [[$account(['subjectRef' => 'subject-new', 'status' => 'removed']), $account(['subjectRef' => 'subject-old', 'status' => 'active'])]]]],
			'subjectRef nowhere' => [['events' => [[$account(['status' => 'removed']), $account(['status' => 'active'])]]]],
			'subjectRef an int' => [['events' => [[$account(['subjectRef' => 5, 'status' => 'removed']), $account($active)]]]],
			'another schema' => [['events' => [[['7', '42', $removed], $account($active)]]]],
			'another register' => [['events' => [[['8', '41', $removed], $account($active)]]]],
			'slugs as register and schema' => [['maps' => 'throw', 'events' => [[['portaliq', 'portalAccount', $removed], $account($active)]]]],
			'ids without mappers' => [['maps' => 'throw', 'events' => [[$account($removed), $account($active)]]]],
			'empty slug in the map' => [['maps' => [['7' => ''], ['41' => 'portalAccount']], 'events' => [[$account($removed), $account($active)]]]],
			'empty slug for a slug value' => [['maps' => [['portaliq' => ''], ['41' => 'portalAccount']], 'events' => [[['portaliq', '41', $removed], $account($active)]]]],
			'int slug in the map' => [['maps' => [['7' => 7], ['41' => 'portalAccount']], 'events' => [[$account($removed), $account($active)]]]],
			'register mapper only' => [['maps' => [['7' => 'portaliq'], 'throw'], 'events' => [[$account($removed), $account($active)], [['7', 'portalAccount', $removed], $account($active)]]]],
			'register empty' => [['events' => [[['', '41', $removed], $account($active)]]]],
			'removal fails' => [['removeThrows' => true, 'events' => [[$account($removed), $account($active)]]]],
			'two removals, maps read once' => [['events' => [[$account($removed), $account($active)], [$account(['subjectRef' => 'subject-2', 'status' => 'removed']), $account(['subjectRef' => 'subject-2'])]]]],
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
		$calls = [];

		$collections = $this->createMock(CitizenCollectionServiceInterface::class);
		$collections->method('removeEverythingOf')->willReturnCallback(
			static function (string $owner) use (&$calls, $case): int {
				$calls[] = ['removeEverythingOf', $owner];
				if (($case['removeThrows'] ?? false) === true) {
					throw new \RuntimeException('store down');
				}

				return ($owner === 'subject-1' ? 3 : 1);
			}
		);

		$listener = new PortalAccountRemovedListener($collections, $this->container(case: $case, calls: $calls), $this->logger(calls: $calls));
		foreach ($case['events'] as $event) {
			$listener->handle($this->event(event: $event));
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $calls;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($calls), true));

	}//end testHandleMatchesTheRecording()

	/**
	 * A container whose mappers answer the case's id-to-slug maps.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(array $case, array &$calls): ContainerInterface {
		$maps = ($case['maps'] ?? [['7' => 'portaliq', '8' => 'other'], ['41' => 'portalAccount', '42' => 'portalMessage']]);
		if ($maps === 'throw') {
			$maps = ['throw', 'throw'];
		}

		$byClass = ['OCA\OpenRegister\Db\RegisterMapper' => $maps[0], 'OCA\OpenRegister\Db\SchemaMapper' => $maps[1]];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$calls, $byClass): object {
				$calls[] = ['container', $id];
				if (($byClass[$id] ?? 'throw') === 'throw') {
					throw new \RuntimeException('no ' . $id);
				}

				return new class($byClass[$id]) {
					/**
					 * @param array<mixed> $map The id-to-slug map.
					 */
					public function __construct(private array $map) {
					}

					/**
					 * @return array<mixed> The id-to-slug map.
					 */
					public function getIdToSlugMap(): array {
						return $this->map;
					}
				};
			}
		);

		return $container;

	}//end container()

	/**
	 * A logger that records every line.
	 *
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return LoggerInterface The double.
	 */
	private function logger(array &$calls): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function ($message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, (string)$message, $context];
				}
			);
		}

		return $logger;

	}//end logger()

	/**
	 * The event for a case entry.
	 *
	 * @param mixed $event [new, old], or 'other'.
	 *
	 * @return Event The event.
	 */
	private function event(mixed $event): Event {
		if ($event === 'other') {
			return new Event();
		}

		[$new, $old] = $event;

		return new ObjectUpdatedEvent(self::entity(object: $new), ($old === null ? null : self::entity(object: $old)));

	}//end event()

	/**
	 * An ObjectEntity with a register, schema and data.
	 *
	 * @param array{0: string|null, 1: string, 2: array<string, mixed>} $object The object.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('acc-1');
		if ($object[0] !== null) {
			$entity->setRegister($object[0]);
		}

		$entity->setSchema($object[1]);
		$entity->setObject($object[2]);

		return $entity;

	}//end entity()
}//end class
