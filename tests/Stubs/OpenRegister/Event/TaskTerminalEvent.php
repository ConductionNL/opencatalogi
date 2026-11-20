<?php

/**
 * Stub of the OpenRegister class of the same name, for unit tests run without
 * OpenRegister installed. Mirrors the real signature on openregister main.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;

class TaskTerminalEvent extends AbstractTaskEvent {
	public function __construct(
		Task $task,
		private readonly bool $committed = true,
	) {
		parent::__construct(task: $task);
	}//end __construct()

	public function isCommitted(): bool {
		return $this->committed;
	}//end isCommitted()

	public function getTaskUuid(): string {
		return (string)$this->getTask()->getUuid();
	}//end getTaskUuid()

	public function getState(): string {
		return (string)$this->getTask()->getState();
	}//end getState()

	public function getOutcome(): ?string {
		return $this->getTask()->getOutcome();
	}//end getOutcome()
}//end class
