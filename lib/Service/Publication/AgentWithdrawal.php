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

use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
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
	 * OpenRegister's task manager, resolved lazily: without OpenRegister's tasks nothing is staged.
	 *
	 * @var string
	 */
	public const TASKS = 'OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface';

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
	 * @param ContainerInterface               $container    Where OpenRegister's task manager comes from.
	 * @param IUserSession                     $session      The person the agent acts for.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly PublicationRightsInterface $rights,
		private readonly PublicationStateServiceInterface $states,
		private readonly ContainerInterface $container,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * Stage one approval task per publication; every publication that cannot take the action is refused.
	 *
	 * @param string       $action DEPUBLISH or ARCHIVE.
	 * @param list<string> $ids    The publication ids, at most MAX_ITEMS.
	 * @param string       $reason Why, as the agent's user states it.
	 *
	 * @return array{staged: list<array{id: string, task: string}>, refused: list<array{id: string, code: string}>}|array{error: string}
	 *         Per item; `error` when tasks are not available or the register is not configured.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function stage(string $action, array $ids, string $reason): array {
		$tasks = $this->taskManager();
		$actor = $this->session->getUser()?->getUID();
		if ($tasks === null || $actor === null || in_array($action, [self::DEPUBLISH, self::ARCHIVE], true) === false) {
			return ['error' => 'tasks_unavailable'];
		}

		$options = $this->publicationOptions();
		if ($options === null) {
			return ['error' => 'not_configured'];
		}

		$answer = ['staged' => [], 'refused' => []];
		foreach ($ids as $id) {
			$request = new AgentWithdrawalRequest(action: $action, id: $id, reason: $reason, actor: $actor);
			$staged  = $this->stageOne(request: $request, options: $options, tasks: $tasks);
			if (isset($staged['task']) === true) {
				$answer['staged'][] = ['id' => $id, 'task' => $staged['task']];
				continue;
			}

			$answer['refused'][] = ['id' => $id, 'code' => $staged['code']];
		}

		return $answer;
	}//end stage()

	/**
	 * OpenRegister's task manager, or null when OpenRegister does not offer one.
	 *
	 * @return object|null The task manager.
	 */
	private function taskManager(): ?object {
		try {
			$tasks = $this->container->get(self::TASKS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($tasks) === true && method_exists($tasks, 'create') === true) {
			return $tasks;
		}

		return null;
	}//end taskManager()

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
	 * @param object                 $tasks   OpenRegister's task manager.
	 *
	 * @return array{task: string}|array{code: string} The task id, or `not_found`, `forbidden`, `not_public`,
	 *                                                 `already_archived` or `not_staged`.
	 */
	private function stageOne(AgentWithdrawalRequest $request, ObjectFindOptions $options, object $tasks): array {
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

		$properties = self::propertiesOf(publication: $publication);
		$refusal    = $this->stateRefusal(action: $request->action, properties: $properties);
		if ($refusal !== null) {
			return ['code' => $refusal];
		}

		try {
			$task = $tasks->create($request->taskData(title: (string)($properties['title'] ?? '')), $request->actor);
		} catch (Throwable) {
			return ['code' => 'not_staged'];
		}

		$uuid = self::taskUuid(task: $task);
		if ($uuid === '') {
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
	 *                     `already_archived` for an archived one, else null.
	 */
	private function stateRefusal(string $action, array $properties): ?string {
		$state = $this->states->stateOf(publication: $properties);
		if ($action === self::DEPUBLISH) {
			$public = [PublicationStateServiceInterface::STATE_PUBLIC, PublicationStateServiceInterface::STATE_SCHEDULED];
			return in_array($state, $public, true) === true ? null : 'not_public';
		}

		return $state === PublicationStateServiceInterface::STATE_ARCHIVED ? 'already_archived' : null;
	}//end stateRefusal()

	/**
	 * The uuid of a created task, empty when it has none.
	 *
	 * @param mixed $task What the task manager returned.
	 *
	 * @return string The uuid.
	 */
	private static function taskUuid(mixed $task): string {
		if (is_object($task) === true && method_exists($task, 'jsonSerialize') === true) {
			$task = $task->jsonSerialize();
		}

		if (is_array($task) === false) {
			return '';
		}

		return (string)($task['uuid'] ?? ($task['id'] ?? ''));
	}//end taskUuid()

	/**
	 * The properties of a publication as OpenRegister answers it.
	 *
	 * @param object $publication The publication.
	 *
	 * @return array<string, mixed> Its properties.
	 */
	private static function propertiesOf(object $publication): array {
		if (method_exists($publication, 'jsonSerialize') === true) {
			$data = $publication->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return get_object_vars($publication);
	}//end propertiesOf()
}//end class
