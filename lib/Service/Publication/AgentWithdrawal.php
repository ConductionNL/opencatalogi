<?php

/**
 * Stages an agent's withdrawal or archiving of publications as a task a publisher completes.
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

use OCA\OpenCatalogi\Contract\AgentWithdrawalCall;
use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Turns an agent's request to withdraw or archive into an OpenRegister approval task, one per publication.
 *
 * The decision of 25 Nov 2026 (Ruben): an agent never withdraws or archives a
 * publication itself; the request becomes a task for the publishers group, on
 * the publication, carrying the reason, and only a publisher completing it
 * takes the action. This class writes nothing about the publication. It reads
 * each one with RBAC as the person the agent acts for, so a publication that
 * person cannot see is `not_found`, one they may not change is `forbidden`, and
 * one that cannot take the action now (not public for a withdrawal, already
 * archived) is refused by name instead of queueing a task nobody can act on.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class AgentWithdrawal implements AgentWithdrawalInterface {

	/**
	 * The kind every staged task carries, with the action in its metadata.
	 *
	 * @var string
	 */
	public const KIND = 'approval';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface      $openRegister OpenRegister's object service and the publication register and schema.
	 * @param PublicationRightsInterface       $rights       The update right on one publication.
	 * @param PublicationStateServiceInterface $states       The state a publication is in.
	 * @param OpenRegisterTasks                $tasks        OpenRegister's task manager, as the person the agent acts for.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly PublicationRightsInterface $rights,
		private readonly PublicationStateServiceInterface $states,
		private readonly OpenRegisterTasks $tasks,
	) {
	}//end __construct()

	/**
	 * Stage one approval task per publication; every publication that cannot take the action is refused.
	 *
	 * @param AgentWithdrawalCall $call The action, the publication ids (at most MAX_ITEMS) and the reason.
	 *
	 * @return array{staged: list<array{id: string, task: string}>, refused: list<array{id: string, code: string}>}|array{error: string}
	 *         Per item; `error` when tasks are not available or the register is not configured.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function stage(AgentWithdrawalCall $call): array {
		$actor = $this->tasks->actor();
		if ($actor === null || in_array($call->action, [self::DEPUBLISH, self::ARCHIVE], true) === false) {
			return ['error' => 'tasks_unavailable'];
		}

		$options = $this->publicationOptions();
		if ($options === null) {
			return ['error' => 'not_configured'];
		}

		$answer = ['staged' => [], 'refused' => []];
		foreach ($call->ids as $id) {
			$request = new AgentWithdrawalRequest(action: $call->action, id: $id, reason: $call->reason, actor: $actor);
			$staged  = $this->stageOne(request: $request, options: $options);
			if (isset($staged['task']) === true) {
				$answer['staged'][] = ['id' => $id, 'task' => $staged['task']];
				continue;
			}

			$answer['refused'][] = ['id' => $id, 'code' => $staged['code']];
		}

		return $answer;
	}//end stage()

	/**
	 * The publication register and schema as find options, or null when they are not configured.
	 *
	 * @return ObjectFindOptions|null The options.
	 */
	private function publicationOptions(): ?ObjectFindOptions {
		try {
			$target = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		} catch (Throwable) {
			return null;
		}

		$register = (string)($target['register'] ?? '');
		$schema   = (string)($target['schema'] ?? '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return new ObjectFindOptions(register: $register, schema: $schema);
	}//end publicationOptions()

	/**
	 * Stage the task for one publication, or say why not.
	 *
	 * @param AgentWithdrawalRequest $request The action, the publication id, the reason and the requester.
	 * @param ObjectFindOptions      $options The publication register and schema.
	 *
	 * @return array{task: string}|array{code: string} The task id, or `not_found`, `forbidden`, `not_public`,
	 *                                                 `already_archived`, `not_published` or `not_staged`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectProperties::fromObject() is a pure read of the answer.
	 */
	private function stageOne(AgentWithdrawalRequest $request, ObjectFindOptions $options): array {
		try {
			$publication = $this->openRegister->objectService()->find(id: $request->id, options: $options, _rbac: true);
		} catch (Throwable) {
			$publication = null;
		}

		if (is_object($publication) === false) {
			return ['code' => 'not_found'];
		}

		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return ['code' => 'forbidden'];
		}

		$properties = ObjectProperties::fromObject(object: $publication);
		$refusal    = $this->stateRefusal(action: $request->action, properties: $properties);
		if ($refusal !== null) {
			return ['code' => $refusal];
		}

		try {
			$uuid = $this->tasks->create(data: $request->taskData(title: (string)($properties['title'] ?? '')), actor: $request->actor);
		} catch (Throwable) {
			return ['code' => 'not_staged'];
		}

		return ['task' => $uuid];
	}//end stageOne()

	/**
	 * Why the publication cannot take the action now, or null.
	 *
	 * @param string               $action     DEPUBLISH or ARCHIVE.
	 * @param array<string, mixed> $properties The publication's properties.
	 *
	 * @return string|null `not_public` for a withdrawal of a publication that is not public or scheduled,
	 *                     `already_archived` for an archived one, `not_published` for an archive of one that
	 *                     was never published (the lifecycle archives only from published), else null.
	 */
	private function stateRefusal(string $action, array $properties): ?string {
		$state = $this->states->stateOf(publication: $properties);
		$public = [PublicationStateServiceInterface::STATE_PUBLIC, PublicationStateServiceInterface::STATE_SCHEDULED];
		if ($action === self::DEPUBLISH && in_array($state, $public, true) === false) {
			return 'not_public';
		}

		if ($action === self::ARCHIVE && $state === PublicationStateServiceInterface::STATE_ARCHIVED) {
			return 'already_archived';
		}

		// The lifecycle archives only from `published` (public, scheduled or withdrawn).
		if ($action === self::ARCHIVE && in_array($state, [...$public, PublicationStateServiceInterface::STATE_WITHDRAWN], true) === false) {
			return 'not_published';
		}

		return null;
	}//end stateRefusal()
}//end class
