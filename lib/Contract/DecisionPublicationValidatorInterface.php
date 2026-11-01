<?php

/**
 * Contract of DecisionPublicationValidator, for callers in other components.
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

use DomainException;

/**
 * Contract of DecisionPublicationValidator, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DecisionPublicationValidatorInterface {
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
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function validate(array $decision, array $decisionType): array;

	/**
	 * The text the type publishes with every one of its records.
	 *
	 * @param array<string, mixed> $decisionType The decision type.
	 *
	 * @return string|null The text, or null when the type declares none.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function publicationText(array $decisionType): ?string;

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
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function applyValidated(array $decision, array $validation): array;
}//end interface
