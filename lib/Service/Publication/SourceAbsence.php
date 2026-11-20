<?php

/**
 * OpenCatalogi Source Absence.
 *
 * Decides what a publication harvested from an external source becomes when
 * the synchronisation that maintains it reports the record gone at its source.
 * The synchronisation writes its markers onto the publication: an end date
 * when its policy ends the record, or only an absence flag when its policy
 * keeps the record until somebody acts.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Turns the source-absence markers on a publication into its depublication or its flag.
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */
final class SourceAbsence {

	/**
	 * The property the synchronisation sets when the source no longer carries the record.
	 *
	 * @var string
	 */
	public const ABSENT = 'absentAtSource';

	/**
	 * The property the synchronisation sets with the run that first did not see the record.
	 *
	 * @var string
	 */
	public const ABSENT_SINCE = 'sourceAbsentSince';

	/**
	 * The property the synchronisation sets when its policy ends the record.
	 *
	 * @var string
	 */
	public const ENDED_AT = 'sourceEndedAt';

	/**
	 * The outcome when the publication is taken down.
	 *
	 * @var string
	 */
	public const DEPUBLISHED = 'depublished';

	/**
	 * The outcome when the publication stays up, flagged for an editor.
	 *
	 * @var string
	 */
	public const FLAGGED = 'flagged';

	/**
	 * What the change from one version of a publication to the next asks for.
	 *
	 * Only the arrival of a marker counts, so a publication an editor publishes
	 * again after it was taken down is not taken down a second time by the next
	 * save that still carries the old marker.
	 *
	 * @param array<string, mixed> $old The publication before the save.
	 * @param array<string, mixed> $new The publication after the save.
	 *
	 * @return string|null DEPUBLISHED, FLAGGED, or null when nothing arrived.
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
	 */
	public function outcome(array $old, array $new): ?string {
		if ($this->isSet(value: ($new[self::ENDED_AT] ?? null)) === true
			&& $this->isSet(value: ($old[self::ENDED_AT] ?? null)) === false
		) {
			return self::DEPUBLISHED;
		}

		if ($this->isSet(value: ($new[self::ENDED_AT] ?? null)) === false
			&& ($new[self::ABSENT] ?? false) === true
			&& ($old[self::ABSENT] ?? false) !== true
		) {
			return self::FLAGGED;
		}

		return null;
	}//end outcome()

	/**
	 * The publication taken down at the moment its source ended it.
	 *
	 * The depublication date is the source's end date, or the current moment
	 * when that date is unreadable or still to come, so the publication is off
	 * every public surface at once. A publication already down by then keeps
	 * its own, earlier date.
	 *
	 * @param array<string, mixed>   $publication The publication after the save.
	 * @param DateTimeInterface|null $now         The moment; defaults to now.
	 *
	 * @return array<string, mixed>|null The publication to save, or null when it is already down.
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
	 */
	public function depublished(array $publication, ?DateTimeInterface $now = null): ?array {
		$moment = $this->moment(now: $now);
		$endedAt = $this->parse(value: ($publication[self::ENDED_AT] ?? null));
		if ($endedAt === null || $endedAt > $moment) {
			$endedAt = $moment;
		}

		$current = $this->parse(value: ($publication['depublicationDate'] ?? null));
		if ($current !== null && $current <= $endedAt) {
			return null;
		}

		$publication['depublicationDate'] = $endedAt->format(DateTimeInterface::ATOM);

		return $publication;
	}//end depublished()

	/**
	 * What an editor is told about a publication its source no longer carries.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return array{since: string|null, endedAt: string|null}|null The markers, or null when its source still carries it.
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
	 */
	public function summary(array $publication): ?array {
		if (($publication[self::ABSENT] ?? false) !== true
			&& $this->isSet(value: ($publication[self::ENDED_AT] ?? null)) === false
		) {
			return null;
		}

		return [
			'since' => $this->text(value: ($publication[self::ABSENT_SINCE] ?? null)),
			'endedAt' => $this->text(value: ($publication[self::ENDED_AT] ?? null)),
		];
	}//end summary()

	/**
	 * A non-empty string value, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The value.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * Whether a marker value is present.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty value.
	 */
	private function isSet(mixed $value): bool {
		return $value !== null && $value !== '' && $value !== false;
	}//end isSet()

	/**
	 * The moment in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function moment(?DateTimeInterface $now): DateTimeImmutable {
		if ($now === null) {
			return new DateTimeImmutable('now', new DateTimeZone('UTC'));
		}

		return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
	}//end moment()

	/**
	 * A date-time value, or null when it is empty or unreadable.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null The moment in UTC.
	 */
	private function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (Exception $e) {
			return null;
		}
	}//end parse()
}//end class
