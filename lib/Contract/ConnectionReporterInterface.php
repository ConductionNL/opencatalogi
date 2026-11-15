<?php

/**
 * Contract of ConnectionReporter, for callers in other components.
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
 * Contract of ConnectionReporter, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface ConnectionReporterInterface {
	/**
	 * Report what a directory sync met.
	 *
	 * @param array<string, mixed> $results The result of DirectoryService::doCronSync().
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportDirectorySync(array $results): bool;

	/**
	 * Report what a broadcast met. Throttled.
	 *
	 * @param array<string, bool> $results Target URL to whether it accepted the broadcast.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportBroadcast(array $results): bool;

	/**
	 * Report a broadcast refused because this instance advertises a local address. Throttled.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportBroadcastFromLocalAddress(): bool;

	/**
	 * Report what a Woo-index readiness check met.
	 *
	 * @param array<string, mixed> $report The report WooReadinessService::runCheck() returns.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooReadiness(array $report): bool;

	/**
	 * Report a readiness check refused because no catalog has Woo sitemaps switched on.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooNotConfigured(): bool;

	/**
	 * Report a readiness check that stopped with an exception.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooCheckStopped(): bool;

	/**
	 * Ask integriq to resolve every connection whose config keys a save wrote.
	 *
	 * Clears that connection's report memory too, so the next outcome reports
	 * at once instead of waiting out the hour. Integriq decides the status
	 * (design D6). Never throws.
	 *
	 * @param array<int, string> $savedKeys The app-config keys the save wrote.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function refreshFromSave(array $savedKeys): array;
}//end interface
