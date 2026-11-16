<?php

/**
 * Raised when a plain summary draft, poll or accept is refused.
 *
 * @category Exception
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
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\PlainSummaryRefused;
use RuntimeException;

/**
 * A refusal with the code the caller answers and the HTTP status it answers with.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
class PlainSummaryRefusal extends RuntimeException implements PlainSummaryRefused {

	/**
	 * Constructor.
	 *
	 * @param string $reason The machine readable reason, such as no-provider.
	 * @param int $status The HTTP status the reason answers with.
	 * @param string $message The text the officer reads.
	 *
	 * @spec exclude pure framework plumbing: an exception carrying its fields.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly int $status,
		string $message = '',
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The reason code of the refusal.
	 *
	 * @return string The reason, such as no-provider.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function reason(): string {
		return $this->reason;

	}//end reason()

	/**
	 * The HTTP status the refusal is answered with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function status(): int {
		return $this->status;

	}//end status()
}//end class
