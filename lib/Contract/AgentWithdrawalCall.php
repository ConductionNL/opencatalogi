<?php

/**
 * One agent call to withdraw or archive publications: the action, the ids and the reason.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The validated arguments of a depublish or archive tool call.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
final class AgentWithdrawalCall {

	/**
	 * Constructor.
	 *
	 * @param string       $action AgentWithdrawalInterface::DEPUBLISH or ::ARCHIVE.
	 * @param list<string> $ids    The publication ids, at most AgentWithdrawalInterface::MAX_ITEMS.
	 * @param string       $reason Why, as the agent's user states it.
	 *
	 * @spec exclude value object constructor.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $ids,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
