<?php

/**
 * The two actions a completed agent task takes: the withdraw button's path and the archive transition.
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

use Throwable;

/**
 * Runs each action as the signed-in user and answers how it ended.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class AgentTaskActions {

	/**
	 * Constructor.
	 *
	 * @param PublicationTakeDown $takeDown   The withdraw button's path.
	 * @param PublishTransition   $transition The lifecycle transitions, archive among them.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationTakeDown $takeDown,
		private readonly PublishTransition $transition,
	) {
	}//end __construct()

	/**
	 * Withdraw the publication with the task's reason, through the withdraw button's path.
	 *
	 * @param PublicationTakeDownRequest $request The publication, its properties, the reason and the actor.
	 *
	 * @return string `withdrawn`, or the take-down's reason: `wrong-state`, `not-saved` or `depublication-unstored`.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function withdraw(PublicationTakeDownRequest $request): string {
		try {
			$this->takeDown->withdraw(request: $request);
		} catch (PublicationTakeDownRefused $e) {
			return $e->reason;
		}

		return 'withdrawn';
	}//end withdraw()

	/**
	 * Archive the publication through the lifecycle's `archive` transition.
	 *
	 * @param string $id The publication id.
	 *
	 * @return string `archived`, or `not_archived` when the lifecycle refused it.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function archive(string $id): string {
		try {
			$this->transition->archive(id: $id);
		} catch (Throwable) {
			return 'not_archived';
		}

		return 'archived';
	}//end archive()
}//end class
