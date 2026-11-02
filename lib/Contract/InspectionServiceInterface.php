<?php

/**
 * Contract of InspectionService, for callers in other components.
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

use DateTimeInterface;
use DomainException;

/**
 * Contract of InspectionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface InspectionServiceInterface {
	/**
	 * Open an inspection on a record, for the term its type declares.
	 *
	 * Any record type that declares an inspection term can use this: the term
	 * comes off the type, never out of this class, so a second type behaves
	 * like the first with its own term.
	 *
	 * @param array<string, mixed> $record The record going on inspection.
	 * @param array<string, mixed> $recordType The type, declaring `inspectionTermDays`.
	 * @param array<int, string> $documents The documents chosen for the inspection.
	 * @param string $openedBy Who opened it.
	 * @param DateTimeInterface|null $start When the window opens; defaults to now.
	 *
	 * @return array<string, mixed> The inspection to save.
	 *
	 * @throws DomainException When the type declares no term, or no document was chosen.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function open(
		array $record,
		array $recordType,
		array $documents,
		string $openedBy,
		?DateTimeInterface $start = null,
	): array;

	/**
	 * Whether the window is open at a moment.
	 *
	 * @param array<string, mixed> $inspection The inspection.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True while the window is open.
	 *
	 * @throws DomainException When the window's dates cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function isOpen(array $inspection, ?DateTimeInterface $now = null): bool;

	/**
	 * What the link answers with.
	 *
	 * Inside the window, the chosen documents and only the chosen documents.
	 * Outside it, a refusal that states the end date, because a reader who
	 * followed a link from a letter deserves to know the period is over rather
	 * than that something is broken.
	 *
	 * @param array<string, mixed> $inspection The inspection.
	 * @param string $token The token from the link.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{readable: boolean, documents: array<int, string>, reason: string|null, endDate: string|null}
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function resolveLink(array $inspection, string $token, ?DateTimeInterface $now = null): array;
}//end interface
