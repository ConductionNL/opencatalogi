<?php

/**
 * Raised when OpenRegister's term engine cannot be reached at all.
 *
 * Separate from a refusal because the answers differ: a refusal is the system
 * working, and an unreachable engine is a term nobody is counting. Computing the
 * date here instead would put a second, undocumented implementation of the
 * Algemene termijnenwet behind a statutory deadline.
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use RuntimeException;

/**
 * The term engine is not there, and nothing guesses in its place.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
 */
class TermEngineUnavailableException extends RuntimeException {
}//end class
