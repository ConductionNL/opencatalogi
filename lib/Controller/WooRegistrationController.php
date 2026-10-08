<?php

/**
 * OpenCatalogi Woo-index Registration Controller.
 *
 * Admin endpoints for the Woo-index connection: read the registration and the
 * root robots.txt rules, request the registration through the gateway, and
 * record that the index confirmed it. Gated like the readiness endpoints.
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\WooRegistrationService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The Woo-index registration and the root robots.txt rules.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
 */
class WooRegistrationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName      The app name.
	 * @param IRequest               $request      The request.
	 * @param WooRegistrationService $registration The registration service.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly WooRegistrationService $registration,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The stored registration, the composed request and the root rules.
	 *
	 * @return JSONResponse The registration panel's data.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function show(): JSONResponse {
		return new JSONResponse(
			[
				'registration' => $this->registration->registration(),
				'request' => $this->registration->compose(),
				'rules' => $this->registration->rootRules(),
			]
		);

	}//end show()

	/**
	 * Request the registration through the gateway.
	 *
	 * @return JSONResponse 200 with the stored registration, or 502 with the composed request when no gateway took it.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function request(): JSONResponse {
		$result = $this->registration->request();
		if ($result['sent'] === false) {
			return new JSONResponse(data: $result, statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($result);

	}//end request()

	/**
	 * Record that the Woo-index confirmed the registration.
	 *
	 * @return JSONResponse The stored registration.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function confirm(): JSONResponse {
		return new JSONResponse(['registration' => $this->registration->confirm()]);

	}//end confirm()
}//end class
