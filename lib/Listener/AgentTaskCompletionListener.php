<?php

/**
 * Completes an agent's withdrawal or archive request when a publisher completes its task.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\AgentTaskCompleted;
use OCA\OpenCatalogi\Service\Publication\AgentTaskCompletion;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * On a committed terminal task that AgentWithdrawal staged and a publisher completed approving, take its action.
 *
 * After commit, so a completion that rolls back takes nothing. Any other task,
 * any other end (cancelled, rejected, expired) and the in-transaction dispatch
 * are ignored. What happened is logged with the task and the publication, so
 * a completion that could not withdraw or archive is visible to an administrator.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 *
 * @template-implements IEventListener<Event>
 */
class AgentTaskCompletionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param AgentTaskCompletion $completion Takes the action as the completing publisher.
	 * @param LoggerInterface     $logger     The log.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly AgentTaskCompletion $completion,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Take the action of a completed agent task.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AgentTaskCompleted::fromTask() is a pure read of the task.
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskTerminalEvent) === false || $event->isCommitted() === false) {
			return;
		}

		$task = $event->getTask()->jsonSerialize();
		$done = AgentTaskCompleted::fromTask(task: $task);
		if ($done === null) {
			return;
		}

		$outcome = $this->completion->complete(done: $done);
		$line    = '[AgentTaskCompletionListener] task ' . (string)($task['uuid'] ?? '') . ' (' . $done->action . ') on publication '
			. $done->publication . ': ' . $outcome;
		if (in_array($outcome, ['withdrawn', 'archived'], true) === true) {
			$this->logger->info($line);
			return;
		}

		$this->logger->warning($line);
	}//end handle()
}//end class
