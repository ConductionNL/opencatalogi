<?php

/**
 * Stub of OpenRegister's LifecycleActionInterface for bare CI, matching the
 * real signature on openregister development.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

interface LifecycleActionInterface {
	/**
	 * Run the action on a transition.
	 *
	 * @param array<string, mixed> $objectData   The object after the lifecycle field moved.
	 * @param array<string, mixed> $previousData The object before the transition.
	 * @param array<string, mixed> $parameters   The declared actionParameters.
	 * @param string               $actionName   The declared action name.
	 *
	 * @return array<string, mixed>
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array;
}
