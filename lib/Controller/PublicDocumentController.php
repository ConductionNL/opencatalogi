<?php

/**
 * One public document of a publication: its metadata and its DiWoo record.
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\PublicDocumentsInterface;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves `GET /api/{catalogSlug}/{id}/documents/{documentId}` and its `/diwoo` twin.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */
class PublicDocumentController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName   The app name.
	 * @param IRequest                 $request   The request.
	 * @param PublicDocumentsInterface $documents Finds and renders one public document.
	 * @param IL10N                    $l10n      Translations.
	 * @param IAppConfig|null          $appConfig Holds the allowed CORS origins.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublicDocumentsInterface $documents,
		private readonly IL10N $l10n,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * One document's metadata with the publication it belongs to.
	 *
	 * @param string $catalogSlug The catalogue.
	 * @param string $id          The publication.
	 * @param string $documentId  The Nextcloud file id.
	 *
	 * @return Response The metadata, or 404 when the document is not a public attachment of a live publication.
	 *
	 * @no-admin-idor-exempt Public read: PublicDocuments::find() reads through the catalogue's read-rule
	 *   guard inside OpenRegister's anonymous scope, lists shared files only and refuses drafts and
	 *   withdrawn files; there is no per-user object to guard.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $catalogSlug, string $id, string $documentId): Response {
		$found = $this->documents->find(catalogSlug: $catalogSlug, publicationId: $id, documentId: $documentId);
		if ($found === null) {
			return $this->notFound(documentId: $documentId);
		}

		return $this->withCorsHeaders(response: new JSONResponse($this->documents->metadata(found: $found)));

	}//end show()

	/**
	 * One document as a `diwoo:Document`.
	 *
	 * @param string $catalogSlug The catalogue.
	 * @param string $id          The publication.
	 * @param string $documentId  The Nextcloud file id.
	 *
	 * @return Response The XML record, or 404 as show() answers it.
	 *
	 * @no-admin-idor-exempt Public read through the same PublicDocuments::find() as show().
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function diwoo(string $catalogSlug, string $id, string $documentId): Response {
		$found = $this->documents->find(catalogSlug: $catalogSlug, publicationId: $id, documentId: $documentId);
		if ($found === null) {
			return $this->notFound(documentId: $documentId);
		}

		return $this->withCorsHeaders(response: new XMLResponse(data: $this->documents->diwoo(found: $found)));

	}//end diwoo()

	/**
	 * The CORS preflight of both routes.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();

	}//end preflightedCors()

	/**
	 * The 404 answer.
	 *
	 * @param string $documentId The document asked for.
	 *
	 * @return Response The answer, with CORS headers.
	 */
	private function notFound(string $documentId): Response {
		return $this->withCorsHeaders(
			response: new JSONResponse(
				data: [
					'error' => 'unknown-document',
					'message' => $this->l10n->t('The document "%s" does not exist or is not public.', [$documentId]),
				],
				statusCode: Http::STATUS_NOT_FOUND
			)
		);

	}//end notFound()
}//end class
