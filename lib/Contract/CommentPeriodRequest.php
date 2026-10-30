<?php

/**
 * What an officer asks for when opening a comment period.
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
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The publication, term, remedy and announcement of a comment period to open.
 *
 * Unvalidated: CommentPeriodServiceInterface::open() refuses what is not a period.
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */
final class CommentPeriodRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $publication         The publication going out for comment.
	 * @param int                  $termDays            How many days the period runs.
	 * @param string               $legalRemedy         The remedy open to a reader.
	 * @param string               $announcementUrl     The official announcement of the period.
	 * @param bool                 $automaticWithdrawal Whether the close withdraws the publication.
	 * @param string               $openedBy            Who opened it.
	 */
	public function __construct(
		public readonly array $publication,
		public readonly int $termDays,
		public readonly string $legalRemedy,
		public readonly string $announcementUrl,
		public readonly bool $automaticWithdrawal,
		public readonly string $openedBy,
	) {

	}//end __construct()

	/**
	 * The id of the publication, trimmed; empty when none was named.
	 *
	 * @return string The publication id.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
	 */
	public function publicationId(): string {
		return trim((string)($this->publication['id'] ?? ''));

	}//end publicationId()
}//end class
