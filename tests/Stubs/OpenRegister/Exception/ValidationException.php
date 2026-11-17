<?php

/**
 * OpenRegister ValidationException stub for unit tests and static analysis.
 *
 * Mirrors openregister/lib/Exception/ValidationException.php on main:
 * ObjectService throws it when an object fails its schema. ListingsController
 * catches it; the unit suite runs without OpenRegister installed and needs the
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
 * An object its schema refused.
 */
class ValidationException extends Exception {
}
