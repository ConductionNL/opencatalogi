<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Mirrors the real value object: one register and schema pair, by id or slug.
 */
final class RegisterSchemaRef {

	/**
	 * Constructor.
	 *
	 * @param string|integer $register The register.
	 * @param string|integer $schema The schema.
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
	) {
	}//end __construct()
}//end class
