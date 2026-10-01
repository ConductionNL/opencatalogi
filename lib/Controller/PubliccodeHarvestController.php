<?php

/**
 * OpenCatalogi GitHub publiccode harvest Controller.
 *
 * The four admin endpoints behind the GitHub harvest section of the admin
 * settings: read the status, set the harvest up, switch it on or off, and run it
 * now. Gated like the Woo-index readiness endpoints (#[AuthorizedAdminSetting]).
 * The three writes keep CSRF on: they write into integriq, change who a flow
 * runs as, or start outbound requests to GitHub.
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
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Admin endpoints for the GitHub publiccode harvest.
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */
class PubliccodeHarvestController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PubliccodeHarvestService $harvest The harvest's status and actions.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PubliccodeHarvestService $harvest,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The harvest's status. Reads only; makes no outbound request.
	 *
	 * @return JSONResponse The status.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function status(): JSONResponse {
		try {
			return new JSONResponse($this->harvest->status());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end status()

	/**
	 * Write the shard synchronizations into integriq.
	 *
	 * @return JSONResponse What was created and updated, or why nothing was.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function setup(): JSONResponse {
		try {
			return new JSONResponse($this->harvest->setUp());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}//end setup()

	/**
	 * Switch the harvest on or off.
	 *
	 * @param bool $enabled Whether the harvest should run.
	 *
	 * @return JSONResponse The flow status after the switch.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function enable(bool $enabled = true): JSONResponse {
		try {
			return new JSONResponse($this->harvest->setEnabled(enabled: $enabled));
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}//end enable()

	/**
	 * Queue a run now.
	 *
	 * @return JSONResponse The queued run.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function run(): JSONResponse {
		try {
			return new JSONResponse($this->harvest->runNow());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}//end run()
}//end class
