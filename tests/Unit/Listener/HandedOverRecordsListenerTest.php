<?php

/**
 * Unit tests for HandedOverRecordsListener.
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

use OCA\OpenCatalogi\Listener\HandedOverRecordsListener;
use OCA\OpenCatalogi\Service\Handover\HandoverState;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A read-only request schema refuses creates and changes, and lets dossiq's stamp through.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-4.1
 */
#[CoversClass(HandedOverRecordsListener::class)]
#[UsesClass(HandoverState::class)]
class HandedOverRecordsListenerTest extends TestCase {

	private const SCHEMA = '17';

	/**
	 * The stored request.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = [
		'reference' => 'WOO-2026-ABCDEF',
		'status' => 'in_progress',
		'termTimer' => 'timer-1',
		'requester' => ['name' => 'A. Jansen', 'email' => 'a@example.nl'],
	];

	/**
	 * The listener over app config with the given read-only flag.
	 *
	 * @param bool $readOnly Whether the schema is read-only.
	 *
	 * @return HandedOverRecordsListener The listener.
	 */
	private function listener(bool $readOnly): HandedOverRecordsListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'woo_request_schema' => self::SCHEMA,
				'woo_requests_read_only' => ($readOnly === true ? '1' : ''),
				default => $default,
			}
		);

		return new HandedOverRecordsListener(
			config: $config,
			state: new HandoverState(config: $config, appManager: $this->createMock(IAppManager::class))
		);
	}//end listener()

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function entity(array $data, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * An update of the stored request to the given data.
	 *
	 * @param array<string, mixed> $data The new data.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectUpdatingEvent The event.
	 */
	private static function update(array $data, string $schema = self::SCHEMA): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(self::entity(data: $data, schema: $schema), self::entity(data: self::STORED, schema: $schema));
	}//end update()

	/**
	 * A status change is refused; dossiq's stamp, whatever the key order, is stored.
	 *
	 * @return void
	 */
	public function testAnUpdateIsRefusedButTheStampIsAccepted(): void {
		$listener = $this->listener(readOnly: true);

		$change = self::update(data: ['status' => 'closed'] + self::STORED);
		$listener->handle(event: $change);
		$this->assertTrue($change->isPropagationStopped());
		$this->assertSame(['message' => HandedOverRecordsListener::REFUSAL], $change->getErrors());

		$stamped = array_reverse(self::STORED, true);
		$stamped['requester'] = ['email' => 'a@example.nl', 'name' => 'A. Jansen'];
		$stamp = self::update(data: $stamped + ['migratedTo' => 'case-1', 'migratedAt' => '2026-11-15T10:00:00+00:00', '@self' => ['id' => 'x']]);
		$listener->handle(event: $stamp);
		$this->assertFalse($stamp->isPropagationStopped());
		$this->assertSame([], $stamp->getErrors());

		$stampAndMore = self::update(data: ['migratedTo' => 'case-1', 'termTimer' => ''] + self::STORED);
		$listener->handle(event: $stampAndMore);
		$this->assertTrue($stampAndMore->isPropagationStopped());
	}//end testAnUpdateIsRefusedButTheStampIsAccepted()

	/**
	 * A new request is refused.
	 *
	 * @return void
	 */
	public function testACreateIsRefused(): void {
		$create = new ObjectCreatingEvent(self::entity(data: self::STORED));

		$this->listener(readOnly: true)->handle(event: $create);

		$this->assertTrue($create->isPropagationStopped());
		$this->assertSame(['message' => HandedOverRecordsListener::REFUSAL], $create->getErrors());
	}//end testACreateIsRefused()

	/**
	 * Before the schema is read-only, and on any other schema, every write goes through.
	 *
	 * @return void
	 */
	public function testOtherWritesGoThrough(): void {
		$writable = $this->listener(readOnly: false);
		$events = [
			new ObjectCreatingEvent(self::entity(data: self::STORED)),
			self::update(data: ['status' => 'closed'] + self::STORED),
		];
		foreach ($events as $event) {
			$writable->handle(event: $event);
			$this->assertFalse($event->isPropagationStopped());
		}

		$readOnly = $this->listener(readOnly: true);
		$other = [
			new ObjectCreatingEvent(self::entity(data: self::STORED, schema: '18')),
			self::update(data: ['status' => 'closed'] + self::STORED, schema: '18'),
		];
		foreach ($other as $event) {
			$readOnly->handle(event: $event);
			$this->assertFalse($event->isPropagationStopped());
		}

		$readOnly->handle(event: new Event());
		$this->addToAssertionCount(1);
	}//end testOtherWritesGoThrough()

	/**
	 * An update whose stored object is unknown cannot be shown to be the stamp, so it is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutTheStoredObjectIsRefused(): void {
		$event = new ObjectUpdatingEvent(self::entity(data: ['migratedTo' => 'case-1'] + self::STORED), null);

		$this->listener(readOnly: true)->handle(event: $event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAnUpdateWithoutTheStoredObjectIsRefused()
}//end class
