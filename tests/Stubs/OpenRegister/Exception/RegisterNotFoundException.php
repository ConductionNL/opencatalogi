<?php

/**
 * OpenRegister RegisterNotFoundException stub for static analysis.
 *
 * Mirrors openregister/lib/Exception/RegisterNotFoundException.php on
 * development (openregister#3996 throws it from the search path for a
 * register reference it cannot resolve). PublicationService catches it;
 * psalm analyses this app without OpenRegister installed and needs the
 * class to exist. The real class ships in openregister.
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
 * Thrown when a register reference names no register.
 */
class RegisterNotFoundException extends Exception {

	/**
	 * @param string         $registerSlugOrId The reference that resolved to nothing.
	 * @param int            $code             HTTP-like status code.
	 * @param Exception|null $previous         The mapper's exception.
	 * @param string|null    $remedies         What the caller can do about it.
	 */
	public function __construct(string $registerSlugOrId, int $code = 404, ?Exception $previous = null, ?string $remedies = null) {
		$message = "Register not found: '" . $registerSlugOrId . "'";
		if ($remedies !== null && $remedies !== '') {
			$message .= ' ' . $remedies;
		}

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
