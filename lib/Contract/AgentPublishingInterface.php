<?php

/**
 * Contract of AgentPublishing, for callers in other components.
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
 * Publishes, for an agent, the publications a person approved.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
interface AgentPublishingInterface {

	/**
	 * The most publications one call publishes.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 50;

	/**
	 * Publish each approved publication; every other one is refused with its reason.
	 *
	 * @param list<string> $ids The publication ids, at most MAX_ITEMS.
	 *
	 * @return array{published: list<string>, refused: list<array{id: string, code: string}>}|array{error: string}
	 *         Per item; `error` when the caller may not publish at all, or the register is not configured.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function publishApproved(array $ids): array;
}//end interface
