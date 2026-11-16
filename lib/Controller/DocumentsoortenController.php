<?php

/**
 * OpenCatalogi DiWoo documentsoorten controller.
 *
 * Serves the national list of document types a publication's document type is
 * chosen from (REQ-DWP-002).
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
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\DocumentTypeVocabularyInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The DiWoo documentsoorten value list.
 *
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
 */
class DocumentsoortenController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n The localization service.
	 * @param IUserSession $userSession The current user session.
	 * @param DocumentTypeVocabularyInterface $vocabulary The bundled DiWoo value lists.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly DocumentTypeVocabularyInterface $vocabulary,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Return the DiWoo documentsoorten a publication's document type is chosen from.
	 *
	 * Read-only; authenticated, with the same posture as the Woo categories. Each row
	 * carries the code, the Dutch label and the resource URI the publication
	 * stores, as the DiWoo value-list XSD enumerates them (REQ-DWP-002).
	 *
	 * @return JSONResponse The documentsoorten and their count.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 *
	 * @no-admin-idor-exempt Returns the national documentsoorten value list bundled
	 *   with the app, the same rows for every caller. The endpoint takes no
	 *   parameter, so there is no object id to scope.
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$rows = $this->vocabulary->documentsoortList();
		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end index()
}//end class
