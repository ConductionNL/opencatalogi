<?php

/**
 * Takes a public or scheduled publication down and stores why: the withdraw button's path, for any caller.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationTransitions;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use Throwable;

/**
 * The withdrawal of one publication, moved out of PublicationStateController so a completed approval task runs it too.
 *
 * Down first: the publication's withdrawn record is saved with RBAC as the
 * signed-in user, so the mistake leaves the public API now; then every channel
 * it reached gets a withdrawal; then the depublication record is stored as the
 * system (its schema is writable by administrators only, and the caller already
 * passed the publish and update checks). Each step that fails says which, so the
 * caller answers as the withdraw button always did.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
class PublicationTakeDown {
	use ReadsObjectProperties;

	/**
	 * Constructor.
	 *
	 * @param PublicationTransitions $registers The states, the withdrawals and the register pairs and ObjectService.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationTransitions $registers,
	) {
	}//end __construct()

	/**
	 * Withdraw one publication and store why.
	 *
	 * @param PublicationTakeDownRequest $request The publication, its properties, the reason, the actor and the tombstone choice.
	 *
	 * @return array{state: string, depublication: array<string, mixed>, outstandingChannels: array<int, string>, complete: bool}
	 *         The stored depublication and the channels still outstanding.
	 *
	 * @throws PublicationTakeDownRefused `wrong-state` (not public or scheduled), `not-saved` (OpenRegister refused the
	 *                                    withdrawn record) or `depublication-unstored` (down, but the record of why failed).
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/tasks.md#task-2.1
	 */
	public function withdraw(PublicationTakeDownRequest $request): array {
		$state = $this->registers->stateOf(publication: $request->properties);
		if ($state !== PublicationStateServiceInterface::STATE_PUBLIC && $state !== PublicationStateServiceInterface::STATE_SCHEDULED) {
			throw new PublicationTakeDownRefused(reason: PublicationTakeDownRefused::WRONG_STATE, state: $state);
		}

		try {
			$this->saveWithdrawn(request: $request);
		} catch (Throwable $e) {
			throw new PublicationTakeDownRefused(reason: PublicationTakeDownRefused::NOT_SAVED, state: $state, previous: $e);
		}

		$depublication = $this->registers->depublish(
			withdrawal: [
				'publication' => array_replace($request->properties, ['id' => $request->id]),
				'reason' => $request->reason,
				'depublishedBy' => $request->actor,
				'channels' => $this->channelsFor(state: $state, properties: $request->properties),
			]
		);

		return $this->store(depublication: $depublication + array_filter($request->tombstone));
	}//end withdraw()

	/**
	 * Store a depublication as the system and answer it with the channels still outstanding.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return array{state: string, depublication: array<string, mixed>, outstandingChannels: array<int, string>, complete: bool}
	 *         The stored depublication and the channels still outstanding.
	 *
	 * @throws PublicationTakeDownRefused `depublication-unstored` when it could not be stored.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function store(array $depublication): array {
		try {
			$configuration = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'depublication_schema');
			$saved = $this->registers->getObjectService()->saveObject(
				object: $this->registers->storable(depublication: $depublication),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $configuration['register'], schema: $configuration['schema']),
					actor: new SaveActor(unowned: true)
				),
				_rbac: false
			);
		} catch (Throwable $e) {
			throw new PublicationTakeDownRefused(
				reason: PublicationTakeDownRefused::UNSTORED,
				state: PublicationStateServiceInterface::STATE_WITHDRAWN,
				previous: $e
			);
		}

		$outstanding = $this->registers->outstandingChannels(depublication: $depublication);

		return [
			'state' => PublicationStateServiceInterface::STATE_WITHDRAWN,
			'depublication' => $this->asArray(object: $saved),
			'outstandingChannels' => $outstanding,
			'complete' => ($outstanding === []),
		];
	}//end store()

	/**
	 * Save the publication's withdrawn record with RBAC on, as the signed-in user.
	 *
	 * @param PublicationTakeDownRequest $request The publication and its properties.
	 *
	 * @return void
	 */
	private function saveWithdrawn(PublicationTakeDownRequest $request): void {
		$configuration = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		$record = $this->registers->withdrawnRecord(publication: $request->properties);
		unset($record['id']);

		$this->registers->getObjectService()->saveObject(
			object: $record,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $configuration['register'], schema: $configuration['schema']),
				uuid: $request->id
			),
			_rbac: true
		);
	}//end saveWithdrawn()

	/**
	 * The channels to tell: those a public publication reached, none for a scheduled one.
	 *
	 * @param string               $state      The state before the withdrawal.
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
}//end class
