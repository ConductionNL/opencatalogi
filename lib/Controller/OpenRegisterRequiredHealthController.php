<?php

/**
 * OpenCatalogi stand-in for the AppHost health endpoint on a too-old OpenRegister.
 *
 * The health endpoint is public, as OpenRegister's GenericHealthController is,
 * so a monitor sees the app as unhealthy with the reason instead of a 500.
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
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Reports the app unhealthy while OpenRegister is too old.
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */
class OpenRegisterRequiredHealthController extends Controller {

	/**
	 * The health answer: unhealthy, with the reason. It names the required
	 * version only, nothing about the instance.
	 *
	 * @return JSONResponse HTTP 503.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'status' => 'error',
				'reason' => 'OpenRegister ' . OpenRegisterCompatibility::REQUIRED_VERSION . ' or newer is required.',
			],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end index()
}//end class
