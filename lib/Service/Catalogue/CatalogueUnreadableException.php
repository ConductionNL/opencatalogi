<?php

/**
 * Raised when the catalogue cannot be read at all.
 *
 * Distinct from an empty catalogue. A caller that cannot tell the two apart
 * reports "nothing to request" to a resident whose municipality is obliged to
 * publish a catalogue, which is the failure this exception exists to prevent.
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
 * The catalogue is unreadable, which is not the same as empty.
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
 */
class CatalogueUnreadableException extends RuntimeException {

}//end class
