<?php

/**
 * Raised when the end date of a term cannot be rolled off a non-working day.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-ends-on-a-working-day-req-pcp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use RuntimeException;

/**
 * The term engine is not there, and no date is guessed in its place.
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-ends-on-a-working-day-req-pcp-002
 */
class TermRollUnavailableException extends RuntimeException {
}//end class
