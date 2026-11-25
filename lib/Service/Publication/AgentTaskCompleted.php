<?php

/**
 * A completed approval task an agent staged: what it asks, on which publication, why, and who completed it.
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
use OCA\OpenCatalogi\Contract\AppIdentity;

/**
 * Read from a terminal OpenRegister task; only a task AgentWithdrawal staged, completed with an approving outcome, reads.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
final class AgentTaskCompleted {

	/**
	 * The outcomes that approve; a completion without an outcome approves too (the publisher marked it done).
	 *
	 * @var list<string|null>
	 */
	public const APPROVING = [null, '', 'approved', 'done'];

	/**
	 * Constructor.
	 *
	 * @param string $action      AgentWithdrawalInterface::DEPUBLISH or ::ARCHIVE.
	 * @param string $publication The publication id.
	 * @param string $reason      The reason the agent's user gave.
	 * @param string $completedBy Who completed the task; empty when OpenRegister did not say.
	 *
	 * @spec exclude value object constructor.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $publication,
		public readonly string $reason,
		public readonly string $completedBy,
	) {
	}//end __construct()

	/**
	 * The completed agent task, or null for any other task or any other end.
	 *
	 * @param array<string, mixed> $task The task as OpenRegister serialises it.
	 *
	 * @return self|null The completion.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public static function fromTask(array $task): ?self {
		$metadata = $task['metadata'] ?? null;
		if (self::isStagedTask(task: $task) === false || is_array($metadata) === false) {
			return null;
		}

		if (self::isApprovingCompletion(task: $task) === false) {
			return null;
		}

		$action      = (string)($metadata['action'] ?? '');
		$publication = (string)($metadata['publication'] ?? '');
		if (in_array($action, [AgentWithdrawalInterface::DEPUBLISH, AgentWithdrawalInterface::ARCHIVE], true) === false || $publication === '') {
			return null;
		}

		return new self(
			action: $action,
			publication: $publication,
			reason: (string)($metadata['reason'] ?? ''),
			completedBy: (string)($task['completedBy'] ?? '')
		);
	}//end fromTask()

	/**
	 * Whether this app staged the task, as an approval.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return bool True for a staged agent task.
	 */
	private static function isStagedTask(array $task): bool {
		return ($task['appId'] ?? null) === AppIdentity::APP_ID && ($task['kind'] ?? null) === AgentWithdrawal::KIND;
	}//end isStagedTask()

	/**
	 * Whether the task ended completed with an approving outcome.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return bool True when completed and approved.
	 */
	private static function isApprovingCompletion(array $task): bool {
		return ($task['state'] ?? null) === 'completed' && in_array($task['outcome'] ?? null, self::APPROVING, true) === true;
	}//end isApprovingCompletion()
}//end class
