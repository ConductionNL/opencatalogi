<?php

/**
 * OpenRegister's task manager, as the person a call acts for.
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

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates tasks through OpenRegister's `TaskManagerInterface` as the signed-in user, resolved lazily.
 *
 * OpenRegister's HTTP-path `create()` pins the requester to that user and
 * refuses a task on an object the user may not read, so a task created here
 * is one the person could have created in the screen.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class OpenRegisterTasks {

	/**
	 * OpenRegister's task manager service.
	 *
	 * @var string
	 */
	public const TASKS = 'OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Where OpenRegister's task manager comes from.
	 * @param IUserSession       $session   The person the call acts for.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * The signed-in user's id, or null when the call has none or OpenRegister offers no tasks.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function actor(): ?string {
		if ($this->manager() === null) {
			return null;
		}

		return $this->session->getUser()?->getUID();
	}//end actor()

	/**
	 * Create a task as the given user and answer its uuid.
	 *
	 * @param array<string, mixed> $data  The task fields.
	 * @param string               $actor The user the task is created as.
	 *
	 * @return string The task uuid.
	 *
	 * @throws RuntimeException When OpenRegister offers no tasks, refuses the task, or answers without a uuid.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function create(array $data, string $actor): string {
		$manager = $this->manager();
		if ($manager === null) {
			throw new RuntimeException(message: 'OpenRegister offers no tasks.');
		}

		$task = $manager->create($data, $actor);
		if (is_object($task) === true && method_exists($task, 'jsonSerialize') === true) {
			$task = $task->jsonSerialize();
		}

		$uuid = '';
		if (is_array($task) === true) {
			$uuid = (string)($task['uuid'] ?? '');
		}

		if ($uuid === '') {
			throw new RuntimeException(message: 'OpenRegister answered the task without a uuid.');
		}

		return $uuid;
	}//end create()

	/**
	 * OpenRegister's task manager, or null when it is not there.
	 *
	 * @return object|null The task manager.
	 */
	private function manager(): ?object {
		try {
			$manager = $this->container->get(self::TASKS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($manager) === true && method_exists($manager, 'create') === true) {
			return $manager;
		}

		return null;
	}//end manager()
}//end class
