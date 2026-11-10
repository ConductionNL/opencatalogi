<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions.
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
 * Mirrors the real value object: what ObjectService::find() reads besides the id and the access flags.
 */
final class ObjectFindOptions {

	/**
	 * Constructor.
	 *
	 * @param mixed $register The register (entity, slug, uuid or id), or null.
	 * @param mixed $schema The schema (entity, slug, uuid or id), or null.
	 * @param array|null $extend The extensions.
	 * @param boolean $files Include files.
	 * @param boolean $render Render the object.
	 * @param boolean $audit Record the read in the audit trail.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
		public readonly ?array $extend = [],
		public readonly bool $files = false,
		public readonly bool $render = true,
		public readonly bool $audit = true,
	) {
	}//end __construct()
}//end class
