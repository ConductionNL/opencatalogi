<?php

/**
 * A national index or a channel we could not reach. Distinct from an index that answered nothing. A withdrawal or a registration that was never delivered is outstanding, never done.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Service\Publication
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

namespace OCA\OpenCatalogi\Service\Publication;

use RuntimeException;

/**
 * A national index or a channel we could not reach. Distinct from an index that answered nothing. A withdrawal or a registration that was never delivered is outstanding, never done.
 */
class IndexUnreachableException extends RuntimeException {

}//end class
