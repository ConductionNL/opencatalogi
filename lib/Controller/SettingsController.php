<?php

/**
 * OpenCatalogi Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenCatalogi application.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for handling settings-related operations in the OpenCatalogi.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsController extends Controller {

	/**
	 * SettingsController constructor.
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param SettingsServiceInterface $settingsService The settings service.
	 * @param IL10N $l10n The localization service.
	 * @param IUserSession $userSession The user session.
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-1.5
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly SettingsServiceInterface $settingsService,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Retrieve the current settings.
	 *
	 * @return JSONResponse JSON response containing the current settings.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->settingsService->getSettings();
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'reading the settings');
		}

	}//end index()

	/**
	 * Write the app-configuration settings and return the persisted values.
	 *
	 * This is the canonical write in OpenRegister's AppHost dialect
	 * ({@see \OCA\OpenRegister\AppHost\Routes}), where `PUT /api/settings` maps to
	 * `settings#update` and `POST /api/settings` (`settings#create`) is the legacy
	 * alias. OpenCatalogi hand-declares its own route table and ships its own
	 * SettingsController, so `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
	 * never aliases the generic in — this leaf owes every `settings#` method itself.
	 * Measured 2026-08-08 on the dev instance: `PUT /api/settings` returned 405
	 * (no route for the verb) while GET and POST returned 200.
	 *
	 * Scope: this writes ONLY the app-configuration payload that `create()` has
	 * always written — `SettingsService::updateSettings()` persists the
	 * allowlisted `*_source` / `*_schema` / `*_register`, DCAT, OOAPI, publishing
	 * and Woo-index keys via `IAppConfig`. It deliberately does NOT absorb the
	 * other surfaces this controller happens to host: `updatePublishingOptions()`
	 * (POST /api/settings/publishing) and `manualImport()`
	 * (POST /api/settings/import) keep their own routes, payload shapes and
	 * response contracts.
	 *
	 * Auth posture is identical to `create()` — admin-only (no @NoAdminRequired,
	 * so NC SecurityMiddleware enforces the admin gate) and auditable via NC's
	 * delegated-admin system through #[AuthorizedAdminSetting], scoped to
	 * OpenCatalogiAdmin.
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`). This is a state-changing
	 * admin endpoint: with the annotation present, any page the admin visited
	 * could silently rewrite this instance's register/schema configuration
	 * cross-origin, because the admin gate is carried by the session cookie the
	 * browser attaches automatically. The annotation used to be justified by the
	 * admin UI calling this with a bare `fetch()` that sends no `requesttoken`;
	 * that justification was a description of a frontend defect, not a reason.
	 * `src/views/settings/Settings.vue` now issues this write through
	 * `@nextcloud/axios`, which attaches `requesttoken` to every request.
	 *
	 * Non-browser API clients are unaffected: NC's `Request::passesCSRFCheck()`
	 * returns true unconditionally for any request carrying `OCS-APIRequest`,
	 * which is what `tests/e2e/ci-seed.sh` already sends.
	 *
	 * The save, and what it sets off (asking integriq to look again at the
	 * connections whose keys it wrote, and queueing the rewrite of every
	 * publication's pages when a link setting changed), is
	 * {@see SettingsServiceInterface::saveSettings()}.
	 *
	 * @return JSONResponse JSON response containing the updated settings.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 * @spec openspec/changes/admin-settings/tasks.md#task-1.5
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function update(): JSONResponse {
		try {
			return new JSONResponse($this->settingsService->saveSettings(data: $this->request->getParams()));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'saving the settings');
		}

	}//end update()

	/**
	 * Legacy alias for {@see update()} — handles the post request to update settings.
	 *
	 * The canonical AppHost route table still ships `settings#create`
	 * (POST /api/settings) for the pre-ADR-066 `index/create/load` dialect, and
	 * OpenCatalogi's own admin UI (`src/views/settings/Settings.vue`, both
	 * `saveConfiguration()` and `saveRegistration()`) still POSTs here, so this
	 * stays reachable and behaviourally identical (ADR-029).
	 *
	 * The attributes below are repeated rather than inherited from `update()`:
	 * NC's SecurityMiddleware evaluates the attributes of the *dispatched* method,
	 * so delegating in the body does not carry `update()`'s posture across.
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces admin gate).
	 * #[AuthorizedAdminSetting] makes this endpoint auditable via NC's delegated-admin
	 * system and scopes it to the OpenCatalogiAdmin settings class (WF3 / wave-12).
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`), for the reason given on
	 * {@see update()}: this is the same state-changing admin write under a
	 * second verb, so exempting it would leave the identical hole open.
	 *
	 * @return JSONResponse JSON response containing the updated settings.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Load the settings from the publication_register.json file.
	 *
	 * @return JSONResponse JSON response containing the settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function load(): JSONResponse {
		try {
			$result = $this->settingsService->loadSettings();
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'loading the configuration');
		}

	}//end load()

	/**
	 * Get the current publishing options.
	 *
	 * @return JSONResponse JSON response containing the current publishing options.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPublishingOptions(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->settingsService->getPublishingOptions();
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'reading the publishing options');
		}

	}//end getPublishingOptions()

	/**
	 * Update the publishing options.
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`) — see {@see update()}. This is
	 * a state-changing admin write; `auto_publish_attachments` /
	 * `auto_publish_objects` decide whether uploaded material becomes publicly
	 * readable, so a cross-origin forgery here has a disclosure consequence.
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
	 * admin gate). #[AuthorizedAdminSetting] matches `update()` / `create()` and
	 * makes this write auditable through NC's delegated-admin system. Until now
	 * this method's ONLY posture declaration was `@NoCSRFRequired`, so it was
	 * the sole marker gate-5 could see; dropping that annotation without adding
	 * this one would have left the method with no declared posture at all.
	 *
	 * @return JSONResponse JSON response containing the updated publishing options.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function updatePublishingOptions(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updatePublishingOptions($data);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'saving the publishing options');
		}

	}//end updatePublishingOptions()

	/**
	 * Get the current federation sync options (interval + bounds).
	 *
	 * Admin-only: this surface controls how often the app hits federation peers.
	 *
	 * @return JSONResponse JSON response containing the sync options.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function getSyncOptions(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->settingsService->getSyncOptions();
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'reading the sync options');
		}

	}//end getSyncOptions()

	/**
	 * Update the federation sync options.
	 *
	 * Admin-only: mutating the sync interval changes how often the app hits
	 * federation peers; a non-admin must not be able to lower it and drown
	 * peer directories in requests.
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`), for the same reason as
	 * {@see update()}: the admin gate on this endpoint is carried by the
	 * session cookie, which a browser attaches automatically, so exempting it
	 * would let any page the admin happens to visit lower this instance's sync
	 * interval cross-origin and point it at peer directories. The admin UI
	 * calls it through @nextcloud/axios, which sends `requesttoken`.
	 *
	 * @return JSONResponse JSON response containing the persisted sync options (post-clamp).
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function updateSyncOptions(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateSyncOptions($data);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'saving the sync options');
		}

	}//end updateSyncOptions()

	/**
	 * Get version information for the app and configuration.
	 *
	 * @return JSONResponse JSON response containing version information.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getVersionInfo(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->settingsService->getVersionInfo();
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return $this->serverError(exception: $e, context: 'reading the version information');
		}

	}//end getVersionInfo()

	/**
	 * Manually trigger configuration import.
	 *
	 * CSRF is ENFORCED here (no `@NoCSRFRequired`) — see {@see update()}. This
	 * rewrites the app's register/schema configuration from the shipped
	 * configuration file, which is the most consequential write in this
	 * controller. `tests/e2e/ci-seed.sh` drives it with `OCS-APIRequest: true`,
	 * which NC's `Request::passesCSRFCheck()` honours unconditionally, so the
	 * seeding path is unaffected.
	 *
	 * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
	 * admin gate). #[AuthorizedAdminSetting] matches `update()` / `create()` and
	 * makes this write auditable through NC's delegated-admin system; as with
	 * `updatePublishingOptions()`, `@NoCSRFRequired` had been this method's only
	 * posture declaration.
	 *
	 * @return JSONResponse JSON response containing import results.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 * @spec openspec/changes/admin-settings/tasks.md#task-1.5
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function manualImport(): JSONResponse {
		try {
			$result = $this->settingsService->manualImport(forceImport: ($this->request->getParams()['force'] ?? null) === true);
			$status = match ($result['success']) {
				true => Http::STATUS_OK,
				default => Http::STATUS_BAD_REQUEST,
			};
			return new JSONResponse(data: $result, statusCode: $status);
		} catch (\Exception $e) {
			$this->settingsService->recordFailure(context: 'importing the configuration', exception: $e);
			$failure = ['success' => false, 'message' => $this->l10n->t('Import failed'), 'error' => $this->genericError()];
			return new JSONResponse(data: $failure, statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end manualImport()
	/**
	 * Log a failed request and answer it with a generic message.
	 *
	 * The exception's message can name tables, paths or OpenRegister internals,
	 * so it goes to the server log only (admin-settings, Security).
	 *
	 * @param \Exception $exception The failure.
	 * @param string     $context   What the request was doing, for the log.
	 *
	 * @return JSONResponse A 500 carrying the generic message.
	 *
	 * @spec openspec/changes/admin-settings/specs/admin-settings.md#security
	 */
	private function serverError(\Exception $exception, string $context): JSONResponse {
		$this->settingsService->recordFailure(context: $context, exception: $exception);
		return new JSONResponse(data: ['error' => $this->genericError()], statusCode: 500);
	}//end serverError()

	/**
	 * The message a caller gets when the server failed.
	 *
	 * @return string The translated message.
	 *
	 * @spec openspec/changes/admin-settings/specs/admin-settings.md#security
	 */
	private function genericError(): string {
		return $this->l10n->t('The server could not complete this request. The details are in the Nextcloud log.');
	}//end genericError()
}//end class
