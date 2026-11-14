<?php

/**
 * Unit tests for TermRollListener: an end date written through an API to an
 * inspection or a comment period lands on a working day, and the save is
 * refused when the term engine cannot be reached.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
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

require_once __DIR__ . '/../Service/Publication/TermRollFixture.php';

use OCA\OpenCatalogi\Listener\TermRollListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Unit\Service\Publication\TermRollFixture;

/**
 * @covers \OCA\OpenCatalogi\Listener\TermRollListener
 * @uses \OCA\OpenCatalogi\Service\Publication\TermRoll
 */
class TermRollListenerTest extends TestCase {
	use TermRollFixture;

	private const INSPECTION_SCHEMA = '201';

	private const COMMENT_PERIOD_SCHEMA = '202';

	/**
	 * The listener, over a reachable engine or none.
	 *
	 * @param bool $engineAvailable Whether the engine can be reached.
	 *
	 * @return TermRollListener The listener.
	 */
	private function listener(bool $engineAvailable = true): TermRollListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'inspection_schema' => self::INSPECTION_SCHEMA,
				'comment_period_schema' => self::COMMENT_PERIOD_SCHEMA,
				default => $default,
			}
		);

		return new TermRollListener($this->termRoll(engineAvailable: $engineAvailable), $config);
	}//end listener()

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string $schema Its schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, string $schema = self::COMMENT_PERIOD_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A comment period saved through the object API with a Saturday end ends on the Monday.
	 *
	 * @return void
	 */
	public function testAnApiEndDateOnASaturdayIsRolledToMonday(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['endDate' => '2026-12-12T17:00:00+00:00']),
			$this->entity(['endDate' => '2026-12-10T17:00:00+00:00'])
		);
		$event->setModifiedData(['other' => 'kept']);

		$this->listener()->handle($event);

		$this->assertSame(
			[
				'other' => 'kept',
				'endDate' => '2026-12-14T17:00:00+00:00',
				'unrolledEndDate' => '2026-12-12T17:00:00+00:00',
				'rolledBy' => 'weekend',
			],
			$event->getModifiedData()
		);
		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());

	}//end testAnApiEndDateOnASaturdayIsRolledToMonday()

	/**
	 * A new inspection created with an end on Christmas Day ends after the holidays.
	 *
	 * @return void
	 */
	public function testANewInspectionsEndIsRolled(): void {
		$event = new ObjectCreatingEvent($this->entity(['endDate' => '2026-12-25T00:00:00+00:00'], self::INSPECTION_SCHEMA));

		$this->listener()->handle($event);

		$this->assertSame('2026-12-28T00:00:00+00:00', $event->getModifiedData()['endDate']);
		$this->assertSame('2026-12-25T00:00:00+00:00', $event->getModifiedData()['unrolledEndDate']);
		$this->assertSame('Kerstmis', $event->getModifiedData()['rolledBy']);

	}//end testANewInspectionsEndIsRolled()

	/**
	 * With no engine the save is refused with the reason, and nothing is rolled.
	 *
	 * @return void
	 */
	public function testAnUnreachableEngineRefusesTheSave(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['endDate' => '2026-12-12T17:00:00+00:00']),
			$this->entity(['endDate' => '2026-12-10T17:00:00+00:00'])
		);

		$this->listener(engineAvailable: false)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringStartsWith('The term engine is unavailable', $event->getErrors()['message']);
		$this->assertSame([], $event->getModifiedData());

	}//end testAnUnreachableEngineRefusesTheSave()

	/**
	 * A save that leaves the end date as stored does not roll it again, nor ask the engine.
	 *
	 * @return void
	 */
	public function testAnUnchangedEndDateIsNotRolledAgain(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['endDate' => '2026-12-12T17:00:00+00:00', 'title' => 'new']),
			$this->entity(['endDate' => '2026-12-12T17:00:00+00:00', 'title' => 'old'])
		);

		$this->listener(engineAvailable: false)->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());

	}//end testAnUnchangedEndDateIsNotRolledAgain()

	/**
	 * An end date on a working day is written as given, with nothing added.
	 *
	 * @return void
	 */
	public function testAWorkingDayIsWrittenAsGiven(): void {
		$event = new ObjectCreatingEvent($this->entity(['endDate' => '2026-12-14T00:00:00+00:00']));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());

	}//end testAWorkingDayIsWrittenAsGiven()

	/**
	 * An object of another schema, or one without a readable end date, is left alone.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndUnreadableDatesAreLeftAlone(): void {
		$listener = $this->listener(engineAvailable: false);

		$cases = [
			new ObjectCreatingEvent($this->entity(['endDate' => '2026-12-12T17:00:00+00:00'], '999')),
			new ObjectCreatingEvent($this->entity(['endDate' => '2026-12-12T17:00:00+00:00'], '')),
			new ObjectCreatingEvent($this->entity(['title' => 'no end'])),
			new ObjectCreatingEvent($this->entity(['endDate' => '  '])),
			new ObjectCreatingEvent($this->entity(['endDate' => 20261212])),
			new ObjectCreatingEvent($this->entity(['endDate' => 'not a date'])),
		];

		foreach ($cases as $index => $event) {
			$listener->handle($event);
			$this->assertSame([], $event->getModifiedData(), 'case ' . $index);
			$this->assertFalse($event->isPropagationStopped(), 'case ' . $index);
		}

	}//end testOtherSchemasAndUnreadableDatesAreLeftAlone()
}//end class
