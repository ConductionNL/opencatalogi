<?php

/**
 * OpenCatalogi Portal Collection Controller.
 *
 * The endpoint actions portaliq forwards for a resident's dossiers (hydra `woo-citizen-journey`, C1 and C2). Every route is a public
 * page without CSRF, because the caller is portaliq's server-to-server
 * forward, not a browser session. The only identity accepted is portaliq's
 * signed `X-Portal-Subject` assertion: without a valid one the answer is 401,
 * and the subject in it is the only owner the services ever compare with.
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
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\CollectionShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves the dossier endpoint actions.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */
class PortalCollectionController extends Controller {
	use AnswersPortalSubjects;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param PortalAssertionVerifier  $verifier    Verifies portaliq's assertion.
	 * @param CitizenCollectionService $collections The dossiers.
	 * @param CollectionShareService   $shares      The share links.
	 * @param LoggerInterface          $logger      The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly CitizenCollectionService $collections,
		private readonly CollectionShareService $shares,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Add a publication or document to a dossier (action `addToDossier`).
	 *
	 * @return JSONResponse The owner's view of the dossier, 201.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function addItem(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->collections->addItem(
				owner: $owner,
				input: [
					'collection' => $this->request->getParam('collection'),
					'title' => $this->request->getParam('title'),
					'publication' => $this->request->getParam('publication'),
					'attachment' => $this->request->getParam('attachment'),
					'note' => $this->request->getParam('note'),
				]
			),
			status: Http::STATUS_CREATED
		);

	}//end addItem()

	/**
	 * The owner's view of one dossier (row action `viewDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-owner-sees-a-depublished-item-as-no-longer-public-req-ccol-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function view(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->collections->view(
				owner: $owner,
				collectionId: $this->param(name: 'collection')
			)
		);

	}//end view()

	/**
	 * Remove one item (row action `removeFromDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function removeItem(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->collections->removeItem(
				owner: $owner,
				collectionId: $this->param(name: 'collection'),
				itemId: $this->param(name: 'itemId')
			)
		);

	}//end removeItem()

	/**
	 * Write a note on an item or on the dossier (row action `noteOnDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function note(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->collections->note(
				owner: $owner,
				collectionId: $this->param(name: 'collection'),
				itemId: $this->param(name: 'itemId'),
				note: $this->param(name: 'note')
			)
		);

	}//end note()

	/**
	 * Make a read-only link (row action `shareDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function share(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->shares->share(
				owner: $owner,
				collectionId: $this->param(name: 'collection')
			)
		);

	}//end share()

	/**
	 * Revoke the read-only link (row action `unshareDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function unshare(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->shares->unshare(
				owner: $owner,
				collectionId: $this->param(name: 'collection')
			)
		);

	}//end unshare()

	/**
	 * Delete a dossier (row action `deleteDossier`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function delete(): JSONResponse {
		return $this->asSubject(
			action: fn (string $owner): array => $this->collections->delete(
				owner: $owner,
				collectionId: $this->param(name: 'collection')
			)
		);

	}//end delete()

}//end class
