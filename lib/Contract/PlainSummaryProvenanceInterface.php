<?php

/**
 * Contract of PlainSummaryProvenance, for callers in other components.
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
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of PlainSummaryProvenance, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
interface PlainSummaryProvenanceInterface {
	/**
	 * The provenance of a summary the current user accepts now.
	 *
	 * @param string      $generatedBy person, or ai for a text assistant's draft.
	 * @param string|null $provider    The text assistant, for an ai draft.
	 *
	 * @return array{generatedBy: string, provider?: string, at: string, acceptedBy: string} The provenance.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function acceptedNow(string $generatedBy, ?string $provider = null): array;

	/**
	 * Hold a provenance for the save of this text.
	 *
	 * @param string               $text       The summary text.
	 * @param array<string, mixed> $provenance The provenance to stamp.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function expect(string $text, array $provenance): void;

	/**
	 * Take the provenance held for this text, once.
	 *
	 * @param string $text The summary text being saved.
	 *
	 * @return array<string, mixed>|null The provenance, or null when none is held.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function take(string $text): ?array;

	/**
	 * Drop the provenance held for this text.
	 *
	 * @param string $text The summary text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function forget(string $text): void;
}//end interface
