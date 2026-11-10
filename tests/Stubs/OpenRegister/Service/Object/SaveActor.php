<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\SaveActor.
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
 * Mirrors the real value object: who saves an object, and who owns it.
 */
final class SaveActor {

	/**
	 * Constructor.
	 *
	 * @param \OCP\IUser|null $currentUser The acting user.
	 * @param string|null $owner The owner to set.
	 * @param boolean $unowned Save without an owner.
	 */
	public function __construct(
		public readonly ?\OCP\IUser $currentUser = null,
		public readonly ?string $owner = null,
		public readonly bool $unowned = false,
	) {
	}//end __construct()
}//end class
