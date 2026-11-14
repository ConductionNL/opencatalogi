<?php

/**
 * OpenCatalogi Term Roll Listener.
 *
 * An end date written through an API (OpenRegister's object API included) is
 * the end of a statutory term all the same, so it lands where the Algemene
 * termijnenwet puts it: on the next working day when it falls on a Saturday,
 * a Sunday or a public holiday. The listener rolls the date before the save,
 * records where it landed before the roll, and refuses the save when the term
 * engine cannot be reached rather than store a date it cannot defend.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-end-date-written-through-the-api-is-rolled-req-ipr-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Rolls an end date written to an inspection or a comment period off a non-working day.
 *
 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-end-date-written-through-the-api-is-rolled-req-ipr-002
 *
 * @template-implements IEventListener<Event>
 */
class TermRollListener implements IEventListener {

	/**
	 * The app config keys that hold the schemas whose end date is a statutory term's end.
	 */
	public const SCHEMA_KEYS = ['inspection_schema', 'comment_period_schema'];

	/**
	 * Constructor.
	 *
	 * @param TermRoll $roll Rolls a date off a non-working day, through OpenRegister.
	 * @param IAppConfig $config Names the inspection and comment period schemas.
	 */
	public function __construct(
		private readonly TermRoll $roll,
		private readonly IAppConfig $config,
	) {

	}//end __construct()

	/**
	 * Roll the end date a save writes, before it is written.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-end-date-written-through-the-api-is-rolled-req-ipr-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->rollEndDate(event: $event, new: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->rollEndDate(event: $event, new: $event->getNewObject(), old: $event->getOldObject());
		}

	}//end handle()

	/**
	 * Roll the end date when this save sets or changes it, or refuse the save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity $new The object as it would be written.
	 * @param ObjectEntity|null $old The object as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-end-date-written-through-the-api-is-rolled-req-ipr-002
	 */
	private function rollEndDate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $new, ?ObjectEntity $old): void {
		if ($this->isTermSchema(object: $new) === false) {
			return;
		}

		$endDate = $this->writtenEndDate(new: $new, old: $old);
		if ($endDate === null) {
			return;
		}

		try {
			$end = $this->roll->roll(at: $endDate);
		} catch (TermRollUnavailableException $e) {
			$event->setErrors(['message' => $e->getMessage()]);
			$event->stopPropagation();
			return;
		}

		if ($end['at']->format('U.u') === $endDate->format('U.u')) {
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				[
					'endDate' => $end['at']->format(DateTimeInterface::ATOM),
					'unrolledEndDate' => $endDate->format(DateTimeInterface::ATOM),
					'rolledBy' => $end['rolledBy'],
				]
			)
		);

	}//end rollEndDate()

	/**
	 * The end date this save sets or changes, or null when it leaves it as stored
	 * or writes nothing that reads as a date.
	 *
	 * @param ObjectEntity $new The object as it would be written.
	 * @param ObjectEntity|null $old The object as stored.
	 *
	 * @return DateTimeImmutable|null The written end date.
	 */
	private function writtenEndDate(ObjectEntity $new, ?ObjectEntity $old): ?DateTimeImmutable {
		$written = ($new->getObject()['endDate'] ?? null);
		if (is_string($written) === false || trim($written) === '') {
			return null;
		}

		if ($old !== null && ($old->getObject()['endDate'] ?? null) === $written) {
			return null;
		}

		try {
			return new DateTimeImmutable($written, new DateTimeZone('UTC'));
		} catch (\Exception) {
			// Not a date: the schema's date-time format refuses it, not this listener.
			return null;
		}

	}//end writtenEndDate()

	/**
	 * Whether an object lives in the inspection or the comment period schema.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True for an inspection or a comment period.
	 */
	private function isTermSchema(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === '') {
			return false;
		}

		foreach (self::SCHEMA_KEYS as $key) {
			if ($schema === $this->config->getValueString('opencatalogi', $key, '')) {
				return true;
			}
		}

		return false;

	}//end isTermSchema()
}//end class
