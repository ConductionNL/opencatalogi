<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectFindTarget.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Mirrors the real value object: the register and schema an object is looked up in.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param mixed $register The register (entity, slug, uuid or id), or null.
	 * @param mixed $schema The schema (entity, slug, uuid or id), or null.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
	) {
	}//end __construct()
}//end class
