<?php

/**
 * OpenCatalogi Value Lists Controller.
 *
 * `GET /api/value-lists`: every entry of every controlled list OpenCatalogi
 * uses, public and CORS-enabled, for an integrator to copy a resource URI.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-every-list-entry-is-on-one-page-for-integrators-req-wvc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\ValueListsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the controlled lists to anyone: national reference data, no stored object.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class ValueListsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ValueListsService $valueLists The lists.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ValueListsService $valueLists,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every list entry, filterable on `scheme`.
	 *
	 * @return JSONResponse 200 with `{results, total, schemes, unavailable}`; 400 for an unknown scheme.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-every-list-entry-is-on-one-page-for-integrators-req-wvc-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[CORS]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$scheme = $this->request->getParam('scheme', null);
		if ($scheme !== null && in_array((string)$scheme, ValueListsService::SCHEMES, true) === false) {
			return new JSONResponse(
				['error' => 'unknown_scheme', 'schemes' => ValueListsService::SCHEMES],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($scheme !== null) {
			$scheme = (string)$scheme;
		}

		try {
			$answer = $this->valueLists->entries(scheme: $scheme);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: value lists failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($answer);
	}//end index()
}//end class
