<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectDeletion.
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
 * Mirrors the real value object: which object to delete, where, by whom, and whether permanently.
 */
final class ObjectDeletion {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The object.
	 * @param ObjectFindTarget $target The register and schema it is in.
	 * @param \OCP\IUser|null $currentUser The acting user.
	 * @param boolean $permanent Delete permanently.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?\OCP\IUser $currentUser = null,
		public readonly bool $permanent = false,
	) {
	}//end __construct()
}//end class
