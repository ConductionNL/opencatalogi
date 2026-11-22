<?php

/**
 * Answers the Text column and the "Read again" action of a publication's file list.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\AttachmentReadingOverviewInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Answers the Text column and the "Read again" action of a publication's file list.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
class AttachmentReadingController extends Controller {
	use AnswersRefusals;

	/**
	 * Constructor.
	 *
	 * Nextcloud answers a caller without a session before these routes run:
	 * they are NoAdminRequired, not PublicPage.
	 *
	 * @param string                             $appName  The app name.
	 * @param IRequest                           $request  The request.
	 * @param IL10N                              $l10n     Localisation.
	 * @param AttachmentReadingOverviewInterface $overview The reading states and the new reading.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly AttachmentReadingOverviewInterface $overview,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Whether search can read each attachment of the publication.
	 *
	 * @param string $id The publication's uuid.
	 *
	 * @return JSONResponse The readings and whether the caller may read again.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		return $this->answerOrRefuse(
			step: function () use ($id): array {
				$publication = $this->overview->requireReadable(publicationId: $id);
				return $this->overview->readings(publication: $publication, publicationId: $id);
			}
		);

	}//end index()

	/**
	 * Queue a new reading of one attachment.
	 *
	 * @param string     $id     The publication's uuid.
	 * @param int|string $fileId The Nextcloud file id.
	 *
	 * @return JSONResponse The queued file.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	#[NoAdminRequired]
	public function readAgain(string $id, int|string $fileId): JSONResponse {
		if (ctype_digit((string)$fileId) === false || (int)$fileId <= 0) {
			return new JSONResponse(
				data: ['error' => 'invalid-file', 'message' => $this->refusalMessage(error: 'invalid-file')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->answerOrRefuse(
			step: function () use ($id, $fileId): array {
				$publication = $this->overview->requireUpdatable(publicationId: $id);
				return $this->overview->readAgain(publication: $publication, publicationId: $id, fileId: (int)$fileId);
			}
		);

	}//end readAgain()

	/**
	 * The message for an error.
	 *
	 * @param string $error The machine-readable error.
	 *
	 * @return string The translated message.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	protected function refusalMessage(string $error): string {
		return match ($error) {
			'forbidden' => $this->l10n->t('You may not change this publication.'),
			'not-found' => $this->l10n->t('This attachment or publication was not found.'),
			'invalid-file' => $this->l10n->t('The file id is not valid.'),
			'not-queued' => $this->l10n->t('The attachment could not be queued for reading.'),
			default => $this->l10n->t('Attachment reading is not set up on this instance.'),
		};

	}//end refusalMessage()
}//end class
