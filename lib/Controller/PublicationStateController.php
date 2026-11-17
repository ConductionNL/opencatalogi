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

use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

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
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param PublicationRightsInterface $rights The update right on one publication, and the publish right.
	 * @param PublicationTransitions $registers The state per move, the withdrawals, a file's withdrawal, and the register pairs and ObjectService.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly PublicationRightsInterface $rights,
		private readonly PublicationTransitions $registers,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Whether the publication is a draft, scheduled, public, withdrawn or archived.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{state, canPublish}`, or 404 when the caller cannot read it.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-screen-offers-publish-actions-only-to-publishers-req-opr-002
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

		return new JSONResponse(
			[
				'state' => $this->registers->stateOf(publication: $this->asArray(object: $publication)),
				'canPublish' => $this->rights->mayPublish(),
			]
		);
	}//end visibility()

	/**
	 * Publish now, or publish again after a withdrawal.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{state: public}`, 403 without the update right or the publish right, 409 when it is already public or archived.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-a-withdrawn-publication-can-be-published-again-req-ppw-003
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		[$publication, $refusal] = $this->guardPublish(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$properties = $this->asArray(object: $publication);
		$state = $this->registers->stateOf(publication: $properties);
		if ($state === PublicationStateServiceInterface::STATE_PUBLIC || $state === PublicationStateServiceInterface::STATE_ARCHIVED) {
			return $this->conflict(state: $state);
		}

		try {
			$this->save(id: $id, record: $this->registers->publishedRecord(publication: $properties));
		} catch (\Throwable $e) {
			return $this->refused(e: $e);
		}

		return new JSONResponse(['state' => PublicationStateServiceInterface::STATE_PUBLIC]);
	}//end publish()

	/**
	 * Withdraw the publication with a reason, and tell every channel it reached.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse The stored depublication and the channels still outstanding, 403 without the publish right.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
	 */
	#[NoAdminRequired]
	public function withdraw(string $id): JSONResponse {
		[$publication, $refusal] = $this->guardPublish(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->reason() === '') {
			return $this->reasonMissing();
		}

		return $this->withdrawWithReason(id: $id, properties: $this->asArray(object: $publication));
	}//end withdraw()

	/**
	 * Take a public or scheduled publication down, then tell every channel it reached.
	 *
	 * @param string $id The publication id.
	 * @param array<string, mixed> $properties The publication's properties.
	 *
	 * @return JSONResponse The stored depublication, or the refusal.
	 */
	private function withdrawWithReason(string $id, array $properties): JSONResponse {
		$state = $this->takeDown(id: $id, properties: $properties);
		if ($state instanceof JSONResponse) {
			return $state;
		}

		return $this->record(
			depublication: $this->registers->depublish(
				withdrawal: [
					'publication' => array_replace($properties, ['id' => $id]),
					'reason' => $this->reason(),
					'depublishedBy' => $this->actor(),
					'channels' => $this->channelsFor(state: $state, properties: $properties),
				]
			)
		);

	}//end withdrawWithReason()

	/**
	 * Save the withdrawn record of a public or scheduled publication.
	 *
	 * Down first: the mistake leaves the public API now, whatever the
	 * channels answer after.
	 *
	 * @param string $id The publication id.
	 * @param array<string, mixed> $properties The publication's properties.
	 *
	 * @return string|JSONResponse The state it was in, or 409 for another state, or the save refusal.
	 */
	private function takeDown(string $id, array $properties): string|JSONResponse {
		$state = $this->registers->stateOf(publication: $properties);
		if ($state !== PublicationStateServiceInterface::STATE_PUBLIC && $state !== PublicationStateServiceInterface::STATE_SCHEDULED) {
			return $this->conflict(state: $state);
		}

		try {
			$this->save(id: $id, record: $this->registers->withdrawnRecord(publication: $properties));
		} catch (\Throwable $e) {
			return $this->refused(e: $e);
		}

		return $state;
	}//end takeDown()

	/**
	 * The channels to tell: those a public publication reached, none for a scheduled one.
	 *
	 * @param string $state The state before the withdrawal.
	 * @param array<string, mixed> $properties The publication's properties.
	 *
	 * @return array<int, string> The channels.
	 */
	private function channelsFor(string $state, array $properties): array {
		if ($state !== PublicationStateServiceInterface::STATE_PUBLIC) {
			return [];
		}

		return $this->registers->channelsReached(publication: $properties);
	}//end channelsFor()

	/**
	 * The withdrawal reason from the request, trimmed.
	 *
	 * @return string The reason, empty when none was given.
	 */
	private function reason(): string {
		return trim((string)$this->request->getParam('reason', ''));
	}//end reason()

	/**
	 * Withdraw one document of a publication with a reason.
	 *
	 * The file loses its public share through OpenRegister's file service, so
	 * it leaves the public API and the DiWoo sitemap; the rest of the
	 * publication stays public.
	 *
	 * @param string $id The publication id.
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

		$reason = $this->reason();
		if ($reason === '') {
			return $this->reasonMissing();
		}

		// The share removal resolves the file on THIS publication, so it runs
		// first: only a file it found has its window ended.
		$notWithdrawn = $this->registers->unpublishFile(publication: $publication, fileId: (int)$fileId);
		if ($notWithdrawn !== null) {
			return new JSONResponse(
				data: ['error' => 'file-not-withdrawn', 'message' => $notWithdrawn],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$notKept = $this->keepWithdrawalOnFile(fileId: (int)$fileId, reason: $reason);
		if ($notKept !== null) {
			return $notKept;
		}

		$depublication = $this->registers->depublish(
			withdrawal: [
				'publication' => ['id' => $id],
				'reason' => $reason,
				'depublishedBy' => $this->actor(),
				'channels' => [],
			]
		);
		$depublication['file'] = $fileId;

		return $this->record(depublication: $depublication);
	}//end withdrawFile()

	/**
	 * Keep a withdrawal on the file itself: its own window ends now, so no
	 * automatic path shares it again, and its labels name the reason and the
	 * editor (REQ-ATT-103, REQ-ATT-104).
	 *
	 * @param int    $fileId The file id.
	 * @param string $reason Why the file is withdrawn.
	 *
	 * @return JSONResponse|null Null when both are kept, else the 500 that says which failed.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
	 */
	private function keepWithdrawalOnFile(int $fileId, string $reason): ?JSONResponse {
		$windowNotEnded = $this->registers->endFileWindow(fileId: $fileId);
		if ($windowNotEnded !== null) {
			return $this->fileStepFailed(error: 'file-window-not-ended', message: $windowNotEnded);
		}

		$notRecorded = $this->registers->recordFileWithdrawal(fileId: $fileId, reason: $reason, editor: $this->actor());
		if ($notRecorded !== null) {
			return $this->fileStepFailed(error: 'file-withdrawal-not-recorded', message: $notRecorded);
		}

		return null;
	}//end keepWithdrawalOnFile()

	/**
	 * The 500 for a step on a file that failed after its share already changed.
	 *
	 * @param string $error   The machine-readable error.
	 * @param string $message Why the step failed.
	 *
	 * @return JSONResponse The 500.
	 */
	private function fileStepFailed(string $error, string $message): JSONResponse {
		return new JSONResponse(
			data: ['error' => $error, 'message' => $message],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end fileStepFailed()

	/**
	 * Put a withdrawn document of a publication back, on purpose, with a reason.
	 *
	 * Only this action reopens a withdrawn file (REQ-ATT-104): the file is
	 * shared again through OpenRegister's file service (which finds it on THIS
	 * publication or refuses), its own window's end is cleared and the reason
	 * and editor are recorded as a depublication record marked `republished`.
	 *
	 * @param string $id     The publication id.
	 * @param string $fileId The file id.
	 *
	 * @return JSONResponse The recorded republication, or the refusal.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
	 */
	#[NoAdminRequired]
	public function republishFile(string $id, string $fileId): JSONResponse {
		[$publication, $refusal] = $this->guardWrite(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$reason = $this->reason();
		if ($reason === '') {
			return new JSONResponse(
				data: ['error' => 'reason-missing', 'message' => $this->l10n->t('Say why it is being put back.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$notShared = $this->registers->publishFile(publication: $publication, fileId: (int)$fileId);
		if ($notShared !== null) {
			return new JSONResponse(
				data: ['error' => 'file-not-republished', 'message' => $notShared],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$windowNotOpen = $this->registers->reopenFileWindow(fileId: (int)$fileId);
		if ($windowNotOpen !== null) {
			return $this->fileStepFailed(error: 'file-window-not-reopened', message: $windowNotOpen);
		}

		$notCleared = $this->registers->clearFileWithdrawal(fileId: (int)$fileId);
		if ($notCleared !== null) {
			return $this->fileStepFailed(error: 'file-withdrawal-not-cleared', message: $notCleared);
		}

		return $this->recordRepublication(
			republication: [
				'publication' => ['id' => $id],
				'file' => $fileId,
				'reason' => $reason,
				'depublishedBy' => $this->actor(),
				'depublishedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
				'withdrawals' => [],
				'republished' => true,
			]
		);
	}//end republishFile()

	/**
	 * Store a republication, past RBAC after guardWrite(), owned by the system.
	 *
	 * @param array<string, mixed> $republication The record.
	 *
	 * @return JSONResponse The stored record, or 503 when it could not be stored.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
	 */
	private function recordRepublication(array $republication): JSONResponse {
		try {
			$configuration = $this->resolveRegisterConfiguration(
				registerKey: 'publication_register',
				schemaKey: 'depublication_schema'
			);
			$saved = $this->registers->getObjectService()->saveObject(
				object: $republication,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $configuration['register'],
							schema: $configuration['schema']
						),
					actor: new SaveActor(unowned: true)
				),
				_rbac: false
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: [
					'error' => 'republication-unstored',
					'message' => $this->l10n->t('It was put back, but the record of why could not be stored.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['republication' => $this->asArray(object: $saved)]);
	}//end recordRepublication()

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
	 * Load the publication for publishing or withdrawing, or the answer that refuses it.
	 *
	 * Every refusal of guardWrite() first, then 403 naming the publishers group
	 * for a caller who may edit but not publish, before anything is sent or saved.
	 *
	 * @param string $id The publication id.
	 *
	 * @return array{0: object|null, 1: JSONResponse|null} The publication, or the refusal.
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
	 */
	private function guardPublish(string $id): array {
		[$publication, $refusal] = $this->guardWrite(id: $id);
		if ($refusal !== null) {
			return [null, $refusal];
		}

		if ($this->rights->mayPublish() === false) {
			return [null, $this->notAPublisher()];
		}

		return [$publication, null];
	}//end guardPublish()

	/**
	 * Store the depublication and answer it with the outstanding channels.
	 *
	 * The depublication schema is writable by administrators only. Callers reach
	 * this only after guardWrite() found the publication and the update right
	 * on it, so the save skips RBAC and stores this one depublication. The system
	 * owns the record, so the editor who wrote it cannot rewrite or delete it.
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
			$saved = $this->registers->getObjectService()->saveObject(
				object: $this->registers->storable(depublication: $depublication),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $configuration['register'],
							schema: $configuration['schema']
						),
					actor: new SaveActor(unowned: true)
				),
				_rbac: false
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: [
					'state' => PublicationStateServiceInterface::STATE_WITHDRAWN,
					'error' => 'depublication-unstored',
					'message' => $this->l10n->t('It was taken down, but the record of why could not be stored.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$stored = $this->asArray(object: $saved);
		$outstanding = $this->registers->outstandingChannels(depublication: $depublication);

		return new JSONResponse(
			[
				'state' => PublicationStateServiceInterface::STATE_WITHDRAWN,
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
			return $this->registers->getObjectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $configuration['register'], schema: $configuration['schema']),
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
	 * @param string $id The publication id.
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

		$this->registers->getObjectService()->saveObject(
			object: $record,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $configuration['register'], schema: $configuration['schema']),
				uuid: $id
			),
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
	 * The answer for a caller who may edit the publication but not publish it.
	 *
	 * @return JSONResponse 403 naming the publishers group.
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
	 */
	private function notAPublisher(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'not-a-publisher',
				'group' => PublishRight::GROUP,
				'message' => $this->l10n->t(
					'Only members of the group %s may publish or withdraw a publication.',
					[PublishRight::GROUP]
				),
			],
			statusCode: Http::STATUS_FORBIDDEN
		);

	}//end notAPublisher()

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
