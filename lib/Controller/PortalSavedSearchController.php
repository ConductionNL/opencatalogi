<?php

/**
 * OpenCatalogi Portal Saved Search Controller.
 *
 * The endpoint actions portaliq forwards for a resident's saved searches
 * (hydra `woo-citizen-journey`, C2): save one from the search block, pause
 * it in one click from a notice, delete it. The only identity accepted is
 * portaliq's signed `X-Portal-Subject` assertion.
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
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCA\OpenCatalogi\Service\Portal\SavedSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves the saved-search endpoint actions.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */
class PortalSavedSearchController extends Controller {
	use AnswersPortalSubjects;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName  The app name.
	 * @param IRequest                $request  The request.
	 * @param PortalAssertionVerifier $verifier Verifies portaliq's assertion.
	 * @param SavedSearchService      $searches The saved searches.
	 * @param LoggerInterface         $logger   The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly SavedSearchService $searches,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Save a search (action `saveSearch`).
	 *
	 * @return JSONResponse The saved search, 201.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function save(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->searches->save(
				owner: $owner,
				input: [
					'title' => $this->request->getParam('title'),
					'query' => $this->request->getParam('query'),
					'frequency' => $this->request->getParam('frequency'),
				]
			),
			status: Http::STATUS_CREATED
		);

	}//end save()

	/**
	 * Stop a saved search's notices (row action `pauseSavedSearch`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function pause(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->searches->pause(
				owner: $owner,
				savedSearchId: $this->param(name: 'savedSearch')
			)
		);

	}//end pause()

	/**
	 * Delete a saved search (row action `deleteSavedSearch`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function delete(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->searches->delete(
				owner: $owner,
				savedSearchId: $this->param(name: 'savedSearch')
			)
		);

	}//end delete()
}//end class
