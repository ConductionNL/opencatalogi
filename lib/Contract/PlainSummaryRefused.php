<?php

/**
 * Contract of PlainSummaryRefusal, for callers in other components.
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
 * Contract of PlainSummaryRefusal, for callers in other components.
 *
 * A plain-summary action that is refused raises this, with the reason code
 * and the HTTP status the caller answers with.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
interface PlainSummaryRefused extends \Throwable {
	/**
	 * The reason code of the refusal.
	 *
	 * @return string The reason, such as no-provider.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function reason(): string;

	/**
	 * The HTTP status the refusal is answered with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function status(): int;
}//end interface
