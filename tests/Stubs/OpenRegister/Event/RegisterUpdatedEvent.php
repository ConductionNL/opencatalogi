<?php

/**
 * Stub for OCA\OpenRegister\Event\RegisterUpdatedEvent.
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
 * Minimal stub for the event OpenRegister dispatches after updating a register.
 */
class RegisterUpdatedEvent extends Event {

	/**
	 * @var Register
	 */
	private Register $newRegister;

	/**
	 * @var Register
	 */
	private Register $oldRegister;

	/**
	 * @param Register $newRegister The register after the update.
	 * @param Register $oldRegister The register before the update.
	 */
	public function __construct(Register $newRegister, Register $oldRegister) {
		parent::__construct();
		$this->newRegister = $newRegister;
		$this->oldRegister = $oldRegister;
	}//end __construct()

	/**
	 * @return Register
	 */
	public function getNewRegister(): Register {
		return $this->newRegister;
	}//end getNewRegister()

	/**
	 * @return Register
	 */
	public function getOldRegister(): Register {
		return $this->oldRegister;
	}//end getOldRegister()

}//end class
