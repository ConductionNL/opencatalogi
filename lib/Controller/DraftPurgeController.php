<?php

/**
 * OpenCatalogi Draft Purge Controller.
 *
 * `DELETE /api/publications/{id}/draft`: an editor deletes a draft that was
 * never released permanently, with everything on it.
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
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\DraftPurgeServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The permanent delete of a never released draft.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param PublicationRightsInterface $rights The update right on one publication.
	 * @param DraftPurgeServiceInterface $purger Proves, lists, removes and logs.
	 * @param OpenRegisterServicesInterface $openRegister Finds the publication with the caller's rights.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly PublicationRightsInterface $rights,
		private readonly DraftPurgeServiceInterface $purger,
		private readonly OpenRegisterServicesInterface $openRegister,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Delete the draft permanently with its files, references and side records.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{removed}`; 401 without a session, 404 when the caller cannot read it,
	 *                      403 without the update right, 409 naming why it was refused, 500 naming
	 *                      what went before a removal failed.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$publication = $this->load(id: $id);
		} catch (Throwable $e) {
			return new JSONResponse(data: ['error' => 'register-unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($publication === null) {
			return new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return new JSONResponse(
				data: ['error' => 'forbidden', 'message' => $this->l10n->t('You may not change this publication.')],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return $this->purge(publication: $publication);

	}//end destroy()

	/**
	 * Run the purge and answer its outcome.
	 *
	 * @param object $publication The publication.
	 *
	 * @return JSONResponse The removed list, the refusal or the partial failure.
	 */
	private function purge(object $publication): JSONResponse {
		try {
			return new JSONResponse(data: ['removed' => $this->purger->purge(publication: $publication)]);
		} catch (DraftPurgeRefusedException $e) {
			return new JSONResponse(
				data: ['error' => $e->reason, 'evidence' => $e->evidence, 'message' => $this->refusalMessage(reason: $e->reason)],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (DraftPurgeFailedException $e) {
			return new JSONResponse(
				data: [
					'error' => 'purge-incomplete',
					'removed' => $e->removed,
					'failedOn' => $e->failedOn,
					'publicationKept' => $e->publicationKept,
					'message' => $this->l10n->t('Deleting stopped part way. What was removed is listed.'),
				],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}//end purge()

	/**
	 * The sentence for a refusal.
	 *
	 * @param string $reason The refusal reason.
	 *
	 * @return string The translated sentence.
	 */
	private function refusalMessage(string $reason): string {
		return match ($reason) {
			DraftPurgeRefusedException::NOT_A_DRAFT => $this->l10n->t('Only a draft or a publication in review can be deleted permanently.'),
			DraftPurgeRefusedException::UNVERIFIABLE => $this->l10n->t('It cannot be checked that this publication was never published, so nothing was deleted.'),
			default => $this->l10n->t('This publication was published before, so it cannot be deleted permanently.'),
		};

	}//end refusalMessage()

	/**
	 * Find the publication with the caller's rights.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication, or null when the caller cannot read it.
	 *
	 * @throws Throwable When the register cannot be reached.
	 */
	private function load(string $id): ?object {
		$configuration = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');

		try {
			return $this->openRegister->installedObjectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $configuration['register'], schema: $configuration['schema']),
				_rbac: true
			);
		} catch (Throwable $e) {
			// Not there, or not readable by this caller: both answer 404.
			if (str_ends_with(get_class($e), 'DoesNotExistException') === true || str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
				return null;
			}

			throw $e;
		}

	}//end load()
}//end class
