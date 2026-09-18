<?php

/**
 * Raised when an external case type catalogue cannot be reached.
 *
 * The national zaaktypecatalogus answering nothing and the gateway being down
 * are different facts, and an administrator who is told "no definitions" when
 * the truth is "we could not ask" will go looking in the wrong place. Every
 * read of an external catalogue either answers or raises this.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Service\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Catalogue;

use RuntimeException;

/**
 * An external catalogue we could not ask, reported as such.
 */
class ExternalCatalogueUnreachableException extends RuntimeException {

}//end class
