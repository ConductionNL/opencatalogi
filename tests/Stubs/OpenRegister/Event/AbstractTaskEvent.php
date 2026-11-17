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
use OCP\EventDispatcher\Event;

abstract class AbstractTaskEvent extends Event {
	public function __construct(private readonly Task $task) {
		parent::__construct();
	}//end __construct()

	public function getTask(): Task {
		return $this->task;
	}//end getTask()
}//end class
