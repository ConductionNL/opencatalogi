<?php

/**
 * OpenRegister HookStoppedException stub for unit tests and static analysis.
 *
 * Mirrors openregister/lib/Exception/HookStoppedException.php on
 * development: MagicMapper throws it when a pre-save listener stops the
 * event, carrying the listener's errors. WooService catches it; the unit
 * suite runs without OpenRegister installed and needs the class to exist.
 * The real class ships in openregister.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * A write a pre-save hook refused.
 */
class HookStoppedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal.
	 * @param array<string, mixed> $errors The hook's errors.
	 */
	public function __construct(
		string $message='Operation blocked by schema hook',
		private readonly array $errors=[],
	) {
		parent::__construct(message: $message, code: 0);
	}//end __construct()

	/**
	 * The hook's errors.
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
