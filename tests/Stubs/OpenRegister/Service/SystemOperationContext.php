<?php

/**
 * Stub for OCA\OpenRegister\Service\SystemOperationContext.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub of OpenRegister's trusted system-operation scope for bare CI:
 * run() opens the scope for the duration of the callable, isActive() reports it.
 */
final class SystemOperationContext {

	/**
	 * Nesting depth of run().
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * Run a callable inside the system scope.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the callable returns.
	 */
	public static function run(callable $operation) {
		self::$depth++;

		try {
			return $operation();
		} finally {
			self::$depth--;
		}
	}//end run()

	/**
	 * Whether the code runs inside run().
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return self::$depth > 0;
	}//end isActive()
}//end class
