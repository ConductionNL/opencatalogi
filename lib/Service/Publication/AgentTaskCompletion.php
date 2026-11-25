<?php

/**
 * Takes the action of a completed agent task as the publisher who completed it.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IUserSession;
use Throwable;

/**
 * Withdraws or archives the publication of a completed agent task, as the withdraw button would for that person.
 *
 * The completion is the human approval (decision 25 Nov 2026). It acts only
 * in the session of the person who completed the task, and only with that
 * person's rights: the publish right, the update right on the publication, and
 * RBAC on every read and write. A completion in another session (a system job,
 * a different user) does nothing, so nobody's completion is turned into
 * someone else's withdrawal. Every outcome is answered as a code for the log.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class AgentTaskCompletion {
	use ReadsObjectProperties;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's object service and the publication register and schema.
	 * @param PublicationRightsInterface  $rights       The publish right, and the update right on one publication.
	 * @param AgentTaskActions            $actions      The withdraw button's path and the archive transition.
	 * @param IUserSession                $session      The person completing the task.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly PublicationRightsInterface $rights,
		private readonly AgentTaskActions $actions,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * Take the task's action, or say why not.
	 *
	 * @param AgentTaskCompleted $done The completed task.
	 *
	 * @return string `withdrawn` or `archived`; else `not_the_completer`, `not_found`, `not_a_publisher`,
	 *                `forbidden`, `not_archived`, or the take-down's own reason (`wrong-state`, `not-saved`,
	 *                `depublication-unstored`).
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function complete(AgentTaskCompleted $done): string {
		$actor = (string)$this->session->getUser()?->getUID();
		if ($actor === '' || ($done->completedBy !== '' && $done->completedBy !== $actor)) {
			return 'not_the_completer';
		}

		$publication = $this->publication(id: $done->publication);
		$refusal     = $this->refusal(publication: $publication);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($done->action === AgentWithdrawalInterface::ARCHIVE) {
			return $this->actions->archive(id: $done->publication);
		}

		return $this->actions->withdraw(
			request: new PublicationTakeDownRequest(
				id: $done->publication,
				properties: $this->asArray(object: $publication),
				reason: $done->reason,
				actor: $actor
			)
		);
	}//end complete()

	/**
	 * The publication as the completer reads it with RBAC, or null.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication.
	 */
	private function publication(string $id): ?object {
		try {
			$target = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
			$found  = $this->openRegister->objectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: (string)$target['register'], schema: (string)$target['schema']),
				_rbac: true
			);
		} catch (Throwable) {
			return null;
		}

		if (is_object($found) === true) {
			return $found;
		}

		return null;
	}//end publication()

	/**
	 * Why the completer may not take the action on this publication, or null.
	 *
	 * @param object|null $publication The publication as read, or null.
	 *
	 * @return string|null `not_found`, `not_a_publisher` or `forbidden`, else null.
	 */
	private function refusal(?object $publication): ?string {
		if ($publication === null) {
			return 'not_found';
		}

		if ($this->rights->mayPublish() === false) {
			return 'not_a_publisher';
		}

		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return 'forbidden';
		}

		return null;
	}//end refusal()
}//end class
