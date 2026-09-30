<?php

/**
 * OpenCatalogi Shared Collection Controller.
 *
 * The read-only share link of a resident's dossier (hydra
 * `woo-citizen-journey`, C1): anyone with the link reads the title, the
 * description and the items that are public at that moment, with their notes.
 * Never the owner. A revoked, forged or unknown token answers 404.
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
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
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
 * Serves a shared dossier to anyone with its link.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */
class SharedCollectionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param CitizenCollectionService $collections The dossiers.
	 * @param LoggerInterface          $logger      The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CitizenCollectionService $collections,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The shared view of a dossier.
	 *
	 * @param string $token The share token.
	 *
	 * @return JSONResponse 200 with the public items, or 404.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[CORS]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $token): JSONResponse {
		try {
			$response = new JSONResponse($this->collections->shared(token: $token));
		} catch (PortalNotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: shared dossier failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// What is public changes by the minute, and a revoked link must stop at once.
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('X-Robots-Tag', 'noindex');
		return $response;

	}//end show()
}//end class
