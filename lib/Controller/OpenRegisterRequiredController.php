<?php

/**
 * OpenCatalogi stand-in for the AppHost controllers on a too-old OpenRegister.
 *
 * OpenCatalogi's dashboard, preferences and metrics routes are bound to
 * OpenRegister's AppHost generics (ADR-040), which exist only in OpenRegister
 * 2.0 and newer. On an older OpenRegister, Application binds those routes to
 * this controller instead, so a page answers with a notice and an API with a
 * 503 naming the cause, never a fatal "Class not found" 500.
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
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\AppInfo\OpenRegisterCompatibility;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Answers the dashboard, preferences and metrics routes with the reason they cannot run.
 *
 * Each method keeps the authentication posture of the generic it stands in for:
 * the page and preferences for any signed-in user, metrics for administrators.
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */
class OpenRegisterRequiredController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string   $appName The app id.
	 * @param IRequest $request The request.
	 * @param IL10N    $l       Translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The app's page: a notice that OpenRegister must be updated.
	 *
	 * @return DataDisplayResponse The notice, HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): DataDisplayResponse {
		$message = htmlspecialchars($this->message(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$title = htmlspecialchars($this->l->t('OpenCatalogi cannot start'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return new DataDisplayResponse(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title></head>'
				. '<body><main><h1>' . $title . '</h1><p>' . $message . '</p></main></body></html>',
			Http::STATUS_SERVICE_UNAVAILABLE,
			['Content-Type' => 'text/html; charset=utf-8']
		);

	}//end page()

	/**
	 * Any deep link into the app: the same notice.
	 *
	 * @return DataDisplayResponse The notice, HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(): DataDisplayResponse {
		return $this->page();

	}//end catchAll()

	/**
	 * Reading a preference: refused with the reason.
	 *
	 * @param string $key The preference key.
	 *
	 * @return JSONResponse HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[NoAdminRequired]
	public function getPreference(string $key): JSONResponse {
		return $this->unavailable();

	}//end getPreference()

	/**
	 * Writing a preference: refused with the reason, nothing stored.
	 *
	 * @param string $key   The preference key.
	 * @param string $value The value.
	 *
	 * @return JSONResponse HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[NoAdminRequired]
	public function setPreference(string $key, string $value=''): JSONResponse {
		return $this->unavailable();

	}//end setPreference()

	/**
	 * Metrics, for administrators: refused with the reason.
	 *
	 * @return JSONResponse HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return $this->unavailable();

	}//end index()

	/**
	 * The JSON answer of an API route that cannot run.
	 *
	 * @return JSONResponse HTTP 503 with the reason.
	 */
	private function unavailable(): JSONResponse {
		return new JSONResponse(
			['error' => 'openregister_too_old', 'message' => $this->message()],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end unavailable()

	/**
	 * The reason, for people.
	 *
	 * @return string The message.
	 */
	private function message(): string {
		return $this->l->t(
			'OpenCatalogi requires OpenRegister %1$s or newer. Ask your administrator to update OpenRegister.',
			[OpenRegisterCompatibility::REQUIRED_VERSION]
		);

	}//end message()
}//end class
