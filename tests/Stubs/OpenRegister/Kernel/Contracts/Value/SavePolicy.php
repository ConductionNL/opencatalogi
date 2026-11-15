<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Mirrors the real value object: how a save is checked and announced.
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param boolean $silent Skip the save events.
	 * @param boolean $validation Validate against the schema.
	 * @param boolean $failIfExists Refuse when the object already exists.
	 * @param boolean $dedupOverride Save past the duplicate check.
	 */
	public function __construct(
		public readonly bool $silent = false,
		public readonly bool $validation = true,
		public readonly bool $failIfExists = false,
		public readonly bool $dedupOverride = false,
	) {
	}//end __construct()
}//end class
