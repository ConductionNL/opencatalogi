<?php

/**
 * Stub of the OpenRegister class of the same name, for unit tests run without
 * OpenRegister installed. Mirrors the real signature on openregister main.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

interface LifecycleGuardInterface {
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
