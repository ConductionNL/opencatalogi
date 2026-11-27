<?php

/**
 * Contract of AgentWithdrawal, for callers in other components.
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
 * Stages, for an agent, the withdrawal or archiving of publications as a task a publisher completes.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
interface AgentWithdrawalInterface {

	/**
	 * The action that withdraws a publication from the public.
	 *
	 * @var string
	 */
	public const DEPUBLISH = 'depublish';

	/**
	 * The action that archives a publication.
	 *
	 * @var string
	 */
	public const ARCHIVE = 'archive';

	/**
	 * The most publications one call stages.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 50;

	/**
	 * Stage one approval task per publication; every publication that cannot take the action is refused.
	 *
	 * Nothing about a publication is written: the task asks a member of the publishers group to take the action.
	 *
	 * @param AgentWithdrawalCall $call The action (DEPUBLISH or ARCHIVE), the publication ids (at most MAX_ITEMS)
	 *                                  and the reason, which travels on the task.
	 *
	 * @return array{staged: list<array{id: string, task: string}>, refused: list<array{id: string, code: string}>}|array{error: string}
	 *         Per item; `error` when tasks are not available or the register is not configured.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function stage(AgentWithdrawalCall $call): array;
}//end interface
