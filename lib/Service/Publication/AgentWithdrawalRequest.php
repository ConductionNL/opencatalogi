<?php

/**
 * One agent request to withdraw or archive a publication, as the task a publisher completes.
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
 * The action, the publication, the reason and the person who asked, and the task payload they make.
 *
 * The task is offered to the publishers group, sits on the publication, and
 * names in its metadata what completing it does and which tool asked for it,
 * so whoever completes it, and the audit trail after, can tell an agent's
 * request from a person's own.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
final class AgentWithdrawalRequest {

	/**
	 * The MCP tool that asks for each action.
	 *
	 * @var array<string, string>
	 */
	public const TOOLS = [
		AgentWithdrawalInterface::DEPUBLISH => 'opencatalogi.depublishPublication',
		AgentWithdrawalInterface::ARCHIVE   => 'opencatalogi.archivePublication',
	];

	/**
	 * Constructor.
	 *
	 * @param string $action DEPUBLISH or ARCHIVE.
	 * @param string $id     The publication id.
	 * @param string $reason Why, as the agent's user states it.
	 * @param string $actor  The user the agent acts for.
	 *
	 * @spec exclude value object constructor.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $id,
		public readonly string $reason,
		public readonly string $actor,
	) {
	}//end __construct()

	/**
	 * The payload OpenRegister's task manager creates the task from.
	 *
	 * @param string $title The publication's title, for the task's own title.
	 *
	 * @return array<string, mixed> The task fields.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function taskData(string $title): array {
		$name = trim($title);
		if ($name === '') {
			$name = $this->id;
		}

		return [
			'title'           => $this->verb() . ' publication "' . $name . '"',
			'description'     => $this->reason,
			'kind'            => AgentWithdrawal::KIND,
			'appId'           => AppIdentity::APP_ID,
			'objectUuid'      => $this->id,
			'candidateGroups' => [PublishRight::GROUP],
			'metadata'        => [
				'action'      => $this->action,
				'publication' => $this->id,
				'reason'      => $this->reason,
				'requestedBy' => $this->actor,
				'tool'        => self::TOOLS[$this->action],
			],
		];
	}//end taskData()

	/**
	 * The verb a task title starts with.
	 *
	 * @return string `Withdraw` or `Archive`.
	 */
	private function verb(): string {
		if ($this->action === AgentWithdrawalInterface::DEPUBLISH) {
			return 'Withdraw';
		}

		return 'Archive';
	}//end verb()
}//end class
