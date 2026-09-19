<?php

/**
 * OpenCatalogi Decision Publication Validator.
 *
 * Publicatieplicht is a property of the besluittype, so the decision type
 * carries the rules and they are validated when a decision is published. The
 * validation runs here because the rule is about publication; the refusal
 * travels back to the case app as a refusal to publish, with the reason, so
 * the person who pressed the button learns what is missing rather than that
 * something went wrong.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Refuses a decision that does not meet its own type's publication rules.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
 */
class DecisionPublicationValidator {

	/**
	 * Validate a decision against its type, and compute the response date.
	 *
	 * The response date is computed from the type's statutory term and the
	 * decision's publication date. It is never taken from the decision: a
	 * typed response date is a date somebody chose, and the statutory one is a
	 * date the law chose.
	 *
	 * A publication date that will not parse is refused rather than read as
	 * absent, because "no date" and "a date nobody can read" send the caller to
	 * different places.
	 *
	 * @param array<string, mixed> $decision The decision to publish.
	 * @param array<string, mixed> $decisionType The decision type's declaration.
	 *
	 * @return array{publishable: boolean, reasons: array<int, string>, responseDate: string|null, publicationText: string|null}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function validate(array $decision, array $decisionType): array {
		$obliged = (bool)($decisionType['publicationObligation'] ?? false);
		$reasons = [];
		$responseDate = null;

		if ($obliged === false) {
			return [
				'publishable' => true,
				'reasons' => [],
				'responseDate' => null,
				'publicationText' => $this->publicationText(decisionType: $decisionType),
			];
		}

		$read = $this->readPublicationDate(decision: $decision);
		$publicationDate = $read['date'];
		$reasons = array_merge($reasons, $read['reasons']);

		$termDays = (int)($decisionType['responseTermDays'] ?? 0);
		if ($termDays <= 0) {
			$reasons[] = 'This decision type obliges publication but declares no statutory response term.';
		}

		if ($publicationDate !== null && $termDays > 0) {
			$responseDate = $publicationDate->add(new DateInterval('P' . $termDays . 'D'))
				->format(DateTimeInterface::ATOM);
		}

		return [
			'publishable' => ($reasons === []),
			'reasons' => $reasons,
			'responseDate' => $responseDate,
			'publicationText' => $this->publicationText(decisionType: $decisionType),
		];

	}//end validate()

	/**
	 * Read the publication date off a decision, saying why it could not be read.
	 *
	 * A missing date and a date nobody can parse are different failures and are
	 * named differently, because they send whoever fixes the decision to
	 * different places.
	 *
	 * @param array<string, mixed> $decision The decision to publish.
	 *
	 * @return array{date: DateTimeImmutable|null, reasons: array<int, string>} The date and what was wrong.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	private function readPublicationDate(array $decision): array {
		$raw = trim((string)($decision['publicationDate'] ?? ''));
		if ($raw === '') {
			return [
				'date' => null,
				'reasons' => ['This decision type obliges publication, and the decision carries no publication date.'],
			];
		}

		try {
			return ['date' => new DateTimeImmutable($raw), 'reasons' => []];
		} catch (\Throwable $e) {
			return [
				'date' => null,
				'reasons' => ['The publication date on this decision cannot be read as a date: "' . $raw . '".'],
			];
		}

	}//end readPublicationDate()

	/**
	 * The text the type publishes with every one of its records.
	 *
	 * @param array<string, mixed> $decisionType The decision type.
	 *
	 * @return string|null The text, or null when the type declares none.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function publicationText(array $decisionType): ?string {
		$text = trim((string)($decisionType['publicationText'] ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;

	}//end publicationText()

	/**
	 * The decision as it is recorded once it passes.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param array{publishable: boolean, reasons: array<int, string>, responseDate: string|null, publicationText: string|null} $validation Result.
	 *
	 * @return array<string, mixed> The decision with its computed response date and its type's text.
	 *
	 * @throws \DomainException When the validation refused.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function applyValidated(array $decision, array $validation): array {
		if ($validation['publishable'] === false) {
			throw new DomainException(message: implode(' ', $validation['reasons']));
		}

		if ($validation['responseDate'] !== null) {
			$decision['responseDate'] = $validation['responseDate'];
		}

		if ($validation['publicationText'] !== null) {
			$decision['publicationText'] = $validation['publicationText'];
		}

		return $decision;

	}//end applyValidated()
}//end class
