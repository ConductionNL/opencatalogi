<?php

/**
 * OpenCatalogi Setup Controller.
 *
 * Implements the ADR-042 first-time-setup server contract: the status, config
 * and privileged-action endpoints that the shared @conduction/nextcloud-vue
 * CnSetupWizard / useSetupStatus engine calls. Status is derived from real
 * app-config + object state (never a stored per-step flag); the actions run with
 * system privileges (_rbac:false) so seeding works on every install path.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Setup\SetupActions;
use OCA\OpenCatalogi\Service\Setup\SetupStatusReport;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller implementing the ADR-042 first-time-setup contract for OpenCatalogi.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/setup-wizard-server-contract/tasks.md#task-2
 */
class SetupController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localization.
	 * @param IUserSession $userSession Current user session (login guard).
	 * @param SetupStatusReport $report The status of every setup step.
	 * @param SetupActions $actions The config writes and the privileged setup actions.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly SetupStatusReport $report,
		private readonly SetupActions $actions,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Report the first-time-setup status (ADR-042 §4).
	 *
	 * Returns `{ version, completed, steps }` where each step's `done` is computed
	 * from real state. Readable by any signed-in user so the wizard engine can
	 * decide gating during boot; it exposes only booleans, no sensitive values.
	 *
	 * Routed at /api/setup/status (registered before the /api/{catalogSlug}
	 * wildcard so `setup` is never mistaken for a catalog slug).
	 *
	 * @return JSONResponse The setup status payload.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function status(): JSONResponse {
		// The wizard-boot status is for signed-in users only; reject anonymous
		// callers explicitly (defence-in-depth alongside the @NoAdminRequired
		// login gate — ADR-005 / no-admin-idor).
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->report->payload());

	}//end status()

	/**
	 * Persist posted setup config keys (ADR-042 §4).
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware enforces the admin
	 * gate) and CSRF-protected (no @NoCSRFRequired). #[AuthorizedAdminSetting]
	 * makes it auditable via NC delegated-admin. Only whitelisted keys are written.
	 *
	 * A save that writes `default_directory_url` asks integriq to resolve the
	 * directory connection again (adopt-connection-registry). That never
	 * throws, does nothing without integriq, and never changes the response.
	 *
	 * @return JSONResponse The saved keys.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function config(): JSONResponse {
		$saved = $this->actions->saveConfig(params: $this->request->getParams());

		return new JSONResponse(['saved' => $saved]);
	}//end config()

	/**
	 * Run a privileged first-time-setup action (ADR-042 §4).
	 *
	 * Admin-only + CSRF-protected. Each action orchestrates an existing service
	 * with system privileges. Returns `{ success, message }` — the wizard treats
	 * `success: false` as a non-fatal step error (HTTP 200) so optional steps stay
	 * skippable.
	 *
	 * @param string $actionId The action to run.
	 *
	 * @return JSONResponse The action result.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-create-first-catalog-privileged-action-onb-006
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function action(string $actionId): JSONResponse {
		return $this->actions->run(actionId: $actionId, request: $this->request);

	}//end action()
}//end class
