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

final class GuardResult {
	private function __construct(private readonly bool $allowed, private readonly ?string $message) {
	}//end __construct()

	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
