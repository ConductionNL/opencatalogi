<?php

/**
 * OpenCatalogi Woo-index harvester-readiness Controller.
 *
 * Admin-facing endpoints for the Woo-index harvester-readiness self-check: run
 * the outside-in validation against the instance's own public WOO surface, and
 * read back the last persisted report without re-running it. Gated exactly like
 * WooController's mutating endpoints (#[AuthorizedAdminSetting]) so delegated
 * admins are audited the same way; fails closed (HTTP 409, zero outbound
 * requests) when no WOO-enabled catalog is configured (WOO-HR-004).
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for the Woo-index harvester-readiness self-check.
 *
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */
class WooReadinessController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param WooReadinessService $wooReadinessService The readiness self-check service.
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what a check met, or nothing when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly WooReadinessService $wooReadinessService,
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Return the persisted readiness report without re-running the self-check.
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). Performs no
	 * outbound requests — reads the appconfig-persisted report only.
	 *
	 * @return JSONResponse The persisted report (or `{"report": null}` when none exists yet).
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md#requirement-readiness-report-is-persisted-and-retrievable-woo-hr-002
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function report(): JSONResponse {
		return new JSONResponse(['report' => $this->wooReadinessService->getPersistedReport()]);
	}//end report()

	/**
	 * Run the harvester-readiness self-check and persist the resulting report.
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). Fails closed with
	 * HTTP 409 `not-configured` and zero outbound requests when no WOO-enabled catalog
	 * exists (WOO-HR-004) — the configuration check runs BEFORE any fetch.
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`), unlike the read-only
	 * `report()` beside it. This POST both PERSISTS a report and issues outbound
	 * HTTP requests to the configured public WOO surface, so a forged
	 * cross-origin call would let any page the admin visits drive this
	 * instance's outbound fetches and overwrite the stored report.
	 * `src/views/settings/Settings.vue` calls it through `@nextcloud/axios`,
	 * which sends `requesttoken`.
	 *
	 * The outcome, the 409 included, is also reported to integriq's connection
	 * registry (adopt-connection-registry). The report never changes the response.
	 *
	 * @return JSONResponse The freshly computed and persisted report, or a 409 error.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md#requirement-readiness-endpoints-are-admin-gated-and-fail-closed-woo-hr-004
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function run(): JSONResponse {
		if ($this->wooReadinessService->hasWooEnabledCatalogs() === false) {
			$this->connectionReporter?->reportWooNotConfigured();
			return new JSONResponse(['error' => 'not-configured'], Http::STATUS_CONFLICT);
		}

		try {
			$report = $this->wooReadinessService->runCheck();
		} catch (\Throwable $e) {
			$this->connectionReporter?->reportWooCheckStopped();
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$this->connectionReporter?->reportWooReadiness(report: $report);

		return new JSONResponse($report);

	}//end run()
}//end class
