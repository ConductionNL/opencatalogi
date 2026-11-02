<?php

/**
 * Contract of WooReadinessService, for callers in other components.
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

/**
 * Contract of WooReadinessService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface HarvesterReadinessServiceInterface {
	/**
	 * Whether at least one WOO-enabled catalog is configured.
	 *
	 * Zero outbound requests. Used by the controller to fail closed (WOO-HR-004)
	 * BEFORE any check runs, guaranteeing an unconfigured instance never performs
	 * an outbound request.
	 *
	 * @return boolean True when at least one catalog has `hasWooSitemap: true`.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function hasWooEnabledCatalogs(): bool;

	/**
	 * Run the check only while at least one catalogue is Woo-enabled.
	 *
	 * With none, no outbound request is made and the stored report stays as
	 * it is (WOO-HR-004). Used by the daily job and by the one queued when a
	 * catalogue is switched on.
	 *
	 * @return array<string, mixed>|null The report, or null when nothing is Woo-enabled.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
	 */
	public function runWhenEnabled(): ?array;

	/**
	 * Run the full outside-in harvester-readiness self-check and persist the report.
	 *
	 * Caller MUST have already verified {@see hasWooEnabledCatalogs()} (WOO-HR-004) —
	 * this method does not itself fail closed on an empty catalog set (it would
	 * simply produce an empty, vacuously "ready" report), so the fail-closed
	 * contract lives in the controller.
	 *
	 * @return array<string, mixed> The report (verdict, checks, registration, baseUrl, checkedAt).
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function runCheck(): array;

	/**
	 * Return the persisted readiness report without performing any outbound request.
	 *
	 * @return array<string, mixed>|null The last persisted report, or null when no run has completed yet.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function getPersistedReport(): ?array;

	/**
	 * Read the tracked Woo-index registration status configuration (WOO-HR-003).
	 *
	 * @return array{status: string, registeredUrl: string, registeredAt: string} The registration config.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function getRegistrationConfig(): array;

	/**
	 * Resolve the WOO-enabled catalogs (`hasWooSitemap: true`) for the configured catalog schema.
	 *
	 * Zero outbound requests — this is a local OpenRegister query, not an HTTP fetch.
	 *
	 * @return array<int, mixed> The catalog objects (OpenRegister entities), possibly empty.
	 *
	 * @spec exclude Local catalog-lookup plumbing shared by hasWooEnabledCatalogs() and runCheck(); no outbound behavior.
	 */
	public function getWooEnabledCatalogs(): array;
}//end interface
