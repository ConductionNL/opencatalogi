<?php

/**
 * Contract of RetentionService, for callers in other components.
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

use RuntimeException;

/**
 * Contract of RetentionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface RetentionServiceInterface {
	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object|null The ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): ?object;

	/**
	 * Resolve the shared OpenRegister DMN decision-table evaluator.
	 *
	 * The RET-004 category match is delegated to this evaluator (hydra
	 * ADR-065, One Engine): opencatalogi translates its stored defaults into
	 * a decision table and never matches rules itself. Same availability
	 * guard as {@see getObjectService()}: unresolvable means "no defaults
	 * configured", logged, never guessed around with an app-local matcher.
	 *
	 * @return object|null The DecisionTableEvaluator, or null when OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function getDecisionTableEvaluator(): ?object;

	/**
	 * Get the configured warning window in days (default 30).
	 *
	 * @return int The warning window in days.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function getWarningWindowDays(): int;

	/**
	 * Get the per-catalog retention defaults configuration.
	 *
	 * Shape: { "<catalogSlug>": { "<woo-category>": { "termMonths": int,
	 * "action": string }, "_fallback": { ... } }, ... }.
	 *
	 * @return array<string, mixed> The decoded defaults map (possibly empty).
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function getRetentionDefaults(): array;

	/**
	 * Store the per-catalog retention defaults configuration.
	 *
	 * @param array<string, mixed> $defaults The defaults map to persist.
	 *
	 * @return array<string, mixed> The stored defaults.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function setRetentionDefaults(array $defaults): array;

	/**
	 * Compute the retention expiry from a publication date and term.
	 *
	 * @param string|null $publicationDate The ISO-8601 publication date.
	 * @param int|null $termMonths The retention term in months.
	 *
	 * @return string|null The computed expiry as ISO-8601, or null when not computable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-metadata-on-the-publication-schema-ret-003
	 */
	public function computeExpiry(?string $publicationDate, ?int $termMonths): ?string;

	/**
	 * Apply per-catalog retention defaults to a publication that lacks them.
	 *
	 * Already-set retention values are never overwritten (RET-004). Returns the
	 * publication array with any newly-applied retention fields and a recomputed
	 * retentionExpiresAt; the caller persists it. The category-to-default match
	 * is delegated to OpenRegister's shared DMN decision-table evaluator (hydra
	 * ADR-065, One Engine): the stored defaults become a FIRST-policy table and
	 * this service never matches rules itself.
	 *
	 * @param array<string, mixed> $publication The publication object data.
	 * @param string $catalogSlug The catalog slug the publication belongs to.
	 *
	 * @return array<string, mixed> The publication with defaults applied.
	 *
	 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function applyDefaults(array $publication, string $catalogSlug): array;

	/**
	 * Stamp retention defaults onto a publication at publication time (RET-004).
	 *
	 * Called from the OR object event listeners when an object first becomes
	 * published. Only publication objects (the configured publication
	 * register/schema) are touched; the matching per-catalog default fills any
	 * retention field the officer left empty, values already set are never
	 * overwritten, and when nothing changes nothing is saved (so the follow-up
	 * OR update event terminates instead of looping).
	 *
	 * @param array<string, mixed> $objectData The OR event object data (with @self envelope).
	 *
	 * @return array<string, mixed>|null The saved publication, or null when nothing was stamped.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function applyDefaultsAtPublication(array $objectData): ?array;

	/**
	 * Evaluate all publications for retention expiry and act per stored policy.
	 *
	 * Dumb evaluator (RET-005): queries publications whose retentionExpiresAt is
	 * within the warning window or in the past, then per retentionAction:
	 *  - review    -> flag only (humans decide in the queue);
	 *  - depublish -> set depublicationDate = now (standard PUB-017 path);
	 *  - archive   -> request the declared lifecycle transition (status=archived,
	 *                 depublicationDate = now), which OpenRegister validates.
	 * Idempotent via retentionLastEvaluatedAt: an object already actioned today is
	 * skipped. Every save is audited by OpenRegister's immutable audit trail.
	 *
	 * @return array<string, int> Counts: expiringSoon, reviewRequired, depublished, archived.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function evaluate(): array;

	/**
	 * Build a retention review-queue summary for the dashboard widget.
	 *
	 * @return array<string, int> Counts keyed expiringSoon, reviewRequired, archived.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function getQueueSummary(): array;

	/**
	 * Record a human disposal/extension decision (who/when/basis/rationale).
	 *
	 * The decision is appended to the publication and persisted via saveObject, so
	 * OpenRegister's immutable audit trail captures it (RET-006/RET-007); no
	 * bespoke audit store. A non-empty rationale is mandatory.
	 *
	 * @param string $publicationId The publication uuid.
	 * @param string $decision The decision verb (extend|depublish|archive|dispose).
	 * @param string $rationale The mandatory rationale.
	 * @param int $extendMonths Extra months to extend the term by (extend only).
	 *
	 * @return array<string, mixed> The updated publication object.
	 *
	 * @throws RuntimeException When inputs are invalid or OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function recordHumanDecision(string $publicationId, string $decision, string $rationale, int $extendMonths = 0): array;

	/**
	 * Build a retention report (rows) for a catalog and date range.
	 *
	 * Derived purely from stored fields + the decision log captured on each object
	 * (which OpenRegister audits); no separate reporting store (RET-009).
	 *
	 * @param string|null $catalogSlug Optional catalog slug filter.
	 * @param string|null $from Optional ISO date lower bound (publication date).
	 * @param string|null $to Optional ISO date upper bound (publication date).
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function buildReport(?string $catalogSlug = null, ?string $from = null, ?string $to = null): array;

	/**
	 * Render report rows as CSV (UTF-8 with BOM).
	 *
	 * @param array<int, array<string, mixed>> $rows The report rows.
	 *
	 * @return string The CSV document.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function renderReportCsv(array $rows): string;
}//end interface
