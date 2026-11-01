<?php

/**
 * Stub for OCA\OpenRegister\Event\RegisterCreatedEvent.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * Minimal stub for the event OpenRegister dispatches after creating a register.
 */
class RegisterCreatedEvent extends Event {

	/**
	 * @var Register
	 */
	private Register $register;

	/**
	 * @param Register $register The created register.
	 */
	public function __construct(Register $register) {
		parent::__construct();
		$this->register = $register;
	}//end __construct()

	/**
	 * @return Register
	 */
	public function getRegister(): Register {
		return $this->register;
	}//end getRegister()

}//end class
