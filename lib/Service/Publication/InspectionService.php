<?php

/**
 * OpenCatalogi Inspection Service.
 *
 * Terinzagelegging: documents on public inspection for exactly the statutory
 * period. The window owns the link, and the link stops working because the
 * window closed, checked at the read rather than by a scheduled job. A job
 * that has not run yet is a link that still works, which is the whole failure.
 *
 * The inspection set is chosen when the publication is made, not by a rule per
 * type, because which documents form part of a decision for inspection is a
 * judgement made per case.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Opens inspection windows and refuses the link once one closes.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */
class InspectionService {

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
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function open(
		array $record,
		array $recordType,
		array $documents,
		string $openedBy,
		?DateTimeInterface $start = null,
	): array {
		$termDays = (int)($recordType['inspectionTermDays'] ?? 0);
		if ($termDays <= 0) {
			throw new DomainException(
				message: 'This record type declares no inspection term, so no inspection can be opened on it.'
			);
		}

		$chosen = array_values(array_unique(array_map('strval', $documents)));
		if ($chosen === []) {
			throw new DomainException(
				message: 'An inspection needs the documents chosen for it. An inspection over nothing is not an inspection.'
			);
		}

		$startsAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($start !== null) {
			$startsAt = new DateTimeImmutable($start->format('Y-m-d\\TH:i:s.uP'));
		}
		$endsAt = $startsAt->add(new DateInterval('P' . $termDays . 'D'));

		return [
			'record' => (string)($record['id'] ?? ''),
			'recordType' => (string)($recordType['slug'] ?? ($recordType['recordType'] ?? '')),
			'documents' => $chosen,
			'startDate' => $startsAt->format(DateTimeInterface::ATOM),
			'endDate' => $endsAt->format(DateTimeInterface::ATOM),
			'termDays' => $termDays,
			'token' => bin2hex(random_bytes(16)),
			'openedBy' => $openedBy,
		];

	}//end open()

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
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function isOpen(array $inspection, ?DateTimeInterface $now = null): bool {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		try {
			$start = new DateTimeImmutable((string)($inspection['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($inspection['endDate'] ?? ''));
		} catch (\Throwable $e) {
			// An unreadable window refuses. Treating it as open publishes
			// documents past their term; treating it as closed silently
			// withholds documents that are owed. Neither is a default worth
			// having, so the caller is told the window cannot be read.
			throw new DomainException(
				message: 'This inspection has a window that cannot be read, so its link is refused.',
				code: 0,
				previous: $e
			);
		}

		return ($moment >= $start && $moment <= $end);

	}//end isOpen()

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
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function resolveLink(array $inspection, string $token, ?DateTimeInterface $now = null): array {
		$expected = (string)($inspection['token'] ?? '');
		if ($expected === '' || hash_equals($expected, $token) === false) {
			return [
				'readable' => false,
				'documents' => [],
				'reason' => 'unknown-inspection',
				'endDate' => null,
			];
		}

		$endDate = (string)($inspection['endDate'] ?? '');

		if ($this->isOpen(inspection: $inspection, now: $now) === false) {
			return [
				'readable' => false,
				'documents' => [],
				'reason' => 'window-closed',
				'endDate' => $endDate,
			];
		}

		$documents = ($inspection['documents'] ?? []);
		if (is_array($documents) === false) {
			$documents = [];
		}

		return [
			'readable' => true,
			'documents' => array_values(array_map('strval', $documents)),
			'reason' => null,
			'endDate' => $endDate,
		];

	}//end resolveLink()
}//end class
