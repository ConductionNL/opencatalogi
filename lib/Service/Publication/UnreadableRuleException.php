<?php

/**
 * A publication rule this app cannot evaluate. A rule nobody can read must
 * not be treated as one that publishes everything, and equally not as one that
 * publishes nothing: it refuses, and somebody fixes it.
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
 * A publication rule this app cannot evaluate. A rule nobody can read must
 * not be treated as one that publishes everything, and equally not as one that
 * publishes nothing: it refuses, and somebody fixes it.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
 */
class UnreadableRuleException extends RuntimeException {

}//end class
