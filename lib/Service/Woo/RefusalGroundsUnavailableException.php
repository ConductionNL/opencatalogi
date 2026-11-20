<?php

/**
 * OpenCatalogi Refusal Grounds Unavailable Exception.
 *
 * Raised when the Woo refusal grounds cannot be read: dossiq is installed but
 * its list throws, or the vendored snapshot is unreadable, or a Woo request
 * path asks for the list while only the snapshot is available (decision D12).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use RuntimeException;

/**
 * The refusal grounds cannot be read, so a save that cites one is refused.
 */
class RefusalGroundsUnavailableException extends RuntimeException {
}//end class
