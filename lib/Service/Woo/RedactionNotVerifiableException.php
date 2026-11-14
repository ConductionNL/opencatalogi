<?php

/**
 * Raised when a person asks to mark a redaction verified that cannot be.
 *
 * Marking a redaction verified is refused on purpose when no mechanically
 * verified redacted file exists, or when its bytes changed since; a caller
 * answers that with 409 and the reason, apart from a missing object.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DomainException;

/**
 * The redaction cannot be marked verified, and the message says why.
 *
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004
 */
class RedactionNotVerifiableException extends DomainException {
}//end class
