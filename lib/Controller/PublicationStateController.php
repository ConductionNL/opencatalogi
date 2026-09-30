<?php

/**
 * OpenCatalogi Publication State Controller.
 *
 * Publish now, withdraw with a reason, publish again, and withdraw one
 * document. Every write loads the publication by the id in the route, with
 * OpenRegister's RBAC on, and asks OpenRegister whether the caller may update
 * that object BEFORE anything is sent or saved.
 *
 * A withdrawal takes the publication down first and writes the letters to the
 * channels after, so a mistake is off the public API at once even when a
 * channel cannot be reached.
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * The publication's visibility, and the three moves an editor makes on it.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
class PublicationStateController extends Controller {
	use ReadsOpenRegisterResults;
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName      The app name.
	 * @param IRequest                $request      The request.
	 * @param IL10N                   $l10n         Localisation.
	 * @param IUserSession            $userSession  The current session.
	 * @param PublicationStateService $states       The visibility state and the record per move.
	 * @param PublicationRights       $rights       The update right on one publication.
	 * @param DepublicationService    $depublisher  Sends the withdrawals.
	 * @param ContainerInterface      $container    Server container, for the register resolver and OpenRegister's file service.
	 * @param ServiceCatalogueService $objects      The OpenRegister reader.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly PublicationStateService $states,
		private readonly PublicationRights $rights,
		private readonly DepublicationService $depublisher,
		private readonly ContainerInterface $container,
		private readonly ServiceCatalogueService $objects,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Whether the publication is a draft, scheduled, public, withdrawn or archived.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{state}`, or 404 when the caller cannot read it.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
	 */
	#[NoAdminRequired]
	public function visibility(string $id): JSONResponse {
		$denied = $this->requireUser();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$publication = $this->load(id: $id);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		if ($publication === null) {
			return $this->notFound();
		}

		return new JSONResponse(['state' => $this->states->stateOf(publication: $this->asArray(object: $publication))]);

	}//end visibility()

	/**
	 * Publish now, or publish again after a withdrawal.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{state: public}`, 403 without the update right, 409 when it is already public or archived.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-a-withdrawn-publication-can-be-published-again-req-ppw-003
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		[$publication, $refusal] = $this->guardWrite(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$properties = $this->asArray(object: $publication);
		$state = $this->states->stateOf(publication: $properties);
		if ($state === PublicationStateService::STATE_PUBLIC || $state === PublicationStateService::STATE_ARCHIVED) {
			return $this->conflict(state: $state);
		}

		try {
			$this->save(id: $id, record: $this->states->publishedRecord(publication: $properties));
		} catch (\Throwable $e) {
			return $this->refused(e: $e);
		}

		return new JSONResponse(['state' => PublicationStateService::STATE_PUBLIC]);

	}//end publish()

	/**
	 * Withdraw the publication with a reason, and tell every channel it reached.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse The stored depublication and the channels still outstanding.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	#[NoAdminRequired]
	public function withdraw(string $id): JSONResponse {
		[$publication, $refusal] = $this->guardWrite(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return $this->reasonMissing();
		}

		$properties = $this->asArray(object: $publication);
		$state = $this->states->stateOf(publication: $properties);
		if ($state !== PublicationStateService::STATE_PUBLIC && $state !== PublicationStateService::STATE_SCHEDULED) {
			return $this->conflict(state: $state);
		}

		// Down first: the mistake leaves the public API now, whatever the
		// channels answer after.
		try {
			$this->save(id: $id, record: $this->states->withdrawnRecord(publication: $properties));
		} catch (\Throwable $e) {
			return $this->refused(e: $e);
		}

		$channels = [];
		if ($state === PublicationStateService::STATE_PUBLIC) {
			$channels = $this->states->channelsReached(publication: $properties);
		}

		$properties['id'] = $id;

		return $this->record(
			depublication: $this->depublisher->depublish(
				publication: $properties,
				reason: $reason,
				depublishedBy: $this->actor(),
				channels: $channels
			)
		);

	}//end withdraw()

	/**
	 * Withdraw one document of a publication with a reason.
	 *
	 * The file loses its public share through OpenRegister's file service, so
	 * it leaves the public API and the DiWoo sitemap; the rest of the
	 * publication stays public.
	 *
	 * @param string $id     The publication id.
	 * @param string $fileId The file id.
	 *
	 * @return JSONResponse The stored depublication naming the document.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-one-document-comes-down-without-its-publication-req-ppw-004
	 */
	#[NoAdminRequired]
	public function withdrawFile(string $id, string $fileId): JSONResponse {
		[$publication, $refusal] = $this->guardWrite(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return $this->reasonMissing();
		}

		try {
			$this->container->get('OCA\OpenRegister\Service\FileService')->unpublishFile(object: $publication, filePath: (int)$fileId);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['error' => 'file-not-withdrawn', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$depublication = $this->depublisher->depublish(
			publication: ['id' => $id],
			reason: $reason,
			depublishedBy: $this->actor(),
			channels: []
		);
		$depublication['file'] = $fileId;

		return $this->record(depublication: $depublication);

	}//end withdrawFile()

	/**
	 * Load the publication for a write, or the answer that refuses it.
	 *
	 * Refuses a caller without a session (401), a publication the caller
	 * cannot read (404) and one the caller may not update (403), in that
	 * order and before anything is sent or saved.
	 *
	 * @param string $id The publication id.
	 *
	 * @return array{0: object|null, 1: JSONResponse|null} The publication, or the refusal.
	 */
	private function guardWrite(string $id): array {
		$denied = $this->requireUser();
		if ($denied !== null) {
			return [null, $denied];
		}

		try {
			$publication = $this->load(id: $id);
		} catch (\Throwable $e) {
			return [null, $this->registerConfigErrorResponse(e: $e)];
		}

		if ($publication === null) {
			return [null, $this->notFound()];
		}

		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return [null, $this->forbidden()];
		}

		return [$publication, null];

	}//end guardWrite()

	/**
	 * Store the depublication and answer it with the outstanding channels.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return JSONResponse The stored depublication, or 503 when it could not be stored.
	 */
	private function record(array $depublication): JSONResponse {
		try {
			$configuration = $this->resolveRegisterConfiguration(
				registerKey: 'publication_register',
				schemaKey: 'depublication_schema'
			);
			$saved = $this->objects->getObjectService()->saveObject(
				object: $this->states->storable(depublication: $depublication),
				extend: [],
				register: $configuration['register'],
				schema: $configuration['schema']
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: [
					'state' => PublicationStateService::STATE_WITHDRAWN,
					'error' => 'depublication-unstored',
					'message' => $this->l10n->t('It was taken down, but the record of why could not be stored.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$stored = $this->asArray(object: $saved);
		$outstanding = $this->depublisher->outstandingChannels(depublication: $depublication);

		return new JSONResponse(
			[
				'state' => PublicationStateService::STATE_WITHDRAWN,
				'depublication' => $stored,
				'outstandingChannels' => $outstanding,
				'complete' => ($outstanding === []),
			]
		);

	}//end record()

	/**
	 * Load a publication by id with RBAC on.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication, or null when the caller cannot read it.
	 */
	private function load(string $id): ?object {
		$configuration = $this->resolveRegisterConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'publication_schema'
		);

		try {
			return $this->objects->getObjectService()->find(
				id: $id,
				register: $configuration['register'],
				schema: $configuration['schema'],
				_rbac: true
			);
		} catch (\Throwable $e) {
			// Not there, or not readable by this caller: both answer 404, so
			// an id nobody may read does not confirm that it exists.
			if (str_ends_with(get_class($e), 'DoesNotExistException') === true || str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
				return null;
			}

			throw $e;
		}

	}//end load()

	/**
	 * Save the publication's record, with RBAC on.
	 *
	 * @param string               $id     The publication id.
	 * @param array<string, mixed> $record The properties.
	 *
	 * @return void
	 */
	private function save(string $id, array $record): void {
		$configuration = $this->resolveRegisterConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'publication_schema'
		);
		unset($record['id']);

		$this->objects->getObjectService()->saveObject(
			object: $record,
			register: $configuration['register'],
			schema: $configuration['schema'],
			uuid: $id,
			_rbac: true
		);

	}//end save()

	/**
	 * Refuse a caller without a session.
	 *
	 * @return JSONResponse|null 401 without a user, null otherwise.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return null;

	}//end requireUser()

	/**
	 * Who is acting, for the record.
	 *
	 * @return string The user id.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end actor()

	/**
	 * The answer for a publication the caller may not update.
	 *
	 * @return JSONResponse 403.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'forbidden', 'message' => $this->l10n->t('You may not change this publication.')],
			statusCode: Http::STATUS_FORBIDDEN
		);

	}//end forbidden()

	/**
	 * The answer for a publication the caller cannot read.
	 *
	 * @return JSONResponse 404.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND);

	}//end notFound()

	/**
	 * The answer when the move does not apply to the current state.
	 *
	 * @param string $state The current state.
	 *
	 * @return JSONResponse 409 naming the state.
	 */
	private function conflict(string $state): JSONResponse {
		return new JSONResponse(data: ['error' => 'wrong-state', 'state' => $state], statusCode: Http::STATUS_CONFLICT);

	}//end conflict()

	/**
	 * The answer for a withdrawal without a reason.
	 *
	 * @return JSONResponse 400.
	 */
	private function reasonMissing(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'reason-missing', 'message' => $this->l10n->t('Say why it is being withdrawn.')],
			statusCode: Http::STATUS_BAD_REQUEST
		);

	}//end reasonMissing()

	/**
	 * The answer when OpenRegister refused the save.
	 *
	 * @param \Throwable $e What OpenRegister threw.
	 *
	 * @return JSONResponse 403 for a permission refusal, 400 otherwise.
	 */
	private function refused(\Throwable $e): JSONResponse {
		if (str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
			return $this->forbidden();
		}

		return new JSONResponse(data: ['error' => 'not-saved', 'message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);

	}//end refused()
}//end class
