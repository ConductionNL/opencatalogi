<?php

/**
 * Contract of TermRoll, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Contract of TermRoll, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface TermRollInterface {
	/**
	 * Roll a given date off a non-working day.
	 *
	 * @param DateTimeInterface $at The given date.
	 * @param string|null $calendarSlug The working calendar, or null for the resolved default.
	 * @param string|null $organisation The organisation the calendar belongs to.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: DateTimeImmutable|null, rolledBy: string|null} Where it lands, and why it moved.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException When the term engine cannot be reached.
	 *
	 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-end-date-written-through-the-api-is-rolled-req-ipr-002
	 */
	public function roll(
		DateTimeInterface $at,
		?string $calendarSlug = null,
		?string $organisation = null,
	): array;
}//end interface
