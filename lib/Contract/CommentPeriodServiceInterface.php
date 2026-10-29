<?php

/**
 * Contract of CommentPeriodService, for callers in other components.
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
 * Contract of CommentPeriodService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CommentPeriodServiceInterface {
	/**
	 * Open a comment period on a publication.
	 *
	 * @param array<string, mixed> $publication The publication going out for comment.
	 * @param int $termDays How many days the period runs.
	 * @param string $legalRemedy The remedy open to a reader, one of {@see REMEDIES}.
	 * @param string $announcementUrl The official announcement of the period.
	 * @param bool $automaticWithdrawal Whether the close withdraws the publication.
	 * @param string $openedBy Who opened it.
	 * @param DateTimeInterface|null $start When it opens; defaults to now.
	 *
	 * @return array<string, mixed> The period to save.
	 *
	 * @throws DomainException On an unknown remedy, a term of no days, or an announcement that is not one.
	 * @throws \OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException When the end date cannot be rolled.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
	 */
	public function open(
		array $publication,
		int $termDays,
		string $legalRemedy,
		string $announcementUrl,
		bool $automaticWithdrawal,
		string $openedBy,
		?DateTimeInterface $start = null,
	): array;

	/**
	 * The reaction form that belongs to a remedy.
	 *
	 * Derived, never pasted. An officer who pastes the form by hand pastes the
	 * zienswijze form onto a bezwaar period sooner or later, and the reader who
	 * follows it files the wrong thing.
	 *
	 * @param string $publicationId The publication the period is on.
	 * @param string $legalRemedy The remedy, one of {@see REMEDIES}.
	 *
	 * @return string The absolute form url.
	 *
	 * @throws DomainException On an unknown remedy.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-reaction-form-follows-from-the-legal-remedy-req-pcp-003
	 */
	public function reactionFormUrl(string $publicationId, string $legalRemedy): string;

	/**
	 * Which of the three states a period is in.
	 *
	 * Derived from the clock, never stored. A stored state is one a job has to
	 * keep true, and a job that has not run yet leaves a closed period reading
	 * as open.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return string One of {@see STATES}.
	 *
	 * @throws DomainException When the period's dates cannot be read.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-reports-one-of-three-states-req-pcp-004
	 */
	public function state(array $period, ?DateTimeInterface $now = null): string;

	/**
	 * What the portal is told about a period: the three states, the remedy, the
	 * form and the announcement. The portal states themselves are portaliq's.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The period as a reader's surface sees it.
	 *
	 * @throws DomainException When the period's dates cannot be read.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-reports-one-of-three-states-req-pcp-004
	 */
	public function publicView(array $period, ?DateTimeInterface $now = null): array;

	/**
	 * Whether the close of this period still owes a withdrawal.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True when the publication should be withdrawn now.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	public function withdrawalDue(array $period, ?DateTimeInterface $now = null): bool;
}//end interface
