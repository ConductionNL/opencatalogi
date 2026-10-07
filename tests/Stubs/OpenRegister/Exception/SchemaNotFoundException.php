<?php

/**
 * OpenRegister SchemaNotFoundException stub for static analysis.
 *
 * Mirrors openregister/lib/Exception/SchemaNotFoundException.php on
 * development (openregister#3996 throws it from the search path for a
 * schema reference it cannot resolve). PublicationService catches it;
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
 * Thrown when a schema reference names no schema.
 */
class SchemaNotFoundException extends Exception {

	/**
	 * @param string         $schemaSlugOrId The reference that resolved to nothing.
	 * @param int            $code           HTTP-like status code.
	 * @param Exception|null $previous       The mapper's exception.
	 */
	public function __construct(string $schemaSlugOrId, int $code = 404, ?Exception $previous = null) {
		parent::__construct(message: "Schema not found: '" . $schemaSlugOrId . "'", code: $code, previous: $previous);
	}//end __construct()
}//end class
