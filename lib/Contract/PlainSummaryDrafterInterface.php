<?php

/**
 * Contract of PlainLanguageDrafter, for callers in other components.
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
 * Contract of PlainLanguageDrafter, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 * Every method raises \OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal
 * with the HTTP status the caller answers when it refuses.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
interface PlainSummaryDrafterInterface {
	/**
	 * The language levels a plain summary is written at.
	 *
	 * @var list<string>
	 */
	public const LEVELS = ['A2', 'B1', 'B2'];

	/**
	 * Whether a text assistant for simplification is installed.
	 *
	 * @return bool True when a provider serves the task type.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function isAvailable(): bool;

	/**
	 * Ask the text assistant for a draft of the publication's plain summary.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return array{taskId: int} The scheduled task.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function draft(string $publicationId): array;

	/**
	 * Read the state of a draft task.
	 *
	 * @param string $publicationId The publication id.
	 * @param int    $taskId        The task the draft was scheduled as.
	 *
	 * @return array<string, mixed> The task state, and the draft when it is done.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function poll(string $publicationId, int $taskId): array;

	/**
	 * Store the plain summary the officer accepted, with its provenance.
	 *
	 * @param string   $publicationId The publication id.
	 * @param string   $text          The accepted summary.
	 * @param string   $level         The language level, one of LEVELS.
	 * @param int|null $fromTaskId    The draft task the text comes from, if any.
	 *
	 * @return array<string, mixed> The stored summary, level and provenance.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function accept(string $publicationId, string $text, string $level, ?int $fromTaskId = null): array;
}//end interface
