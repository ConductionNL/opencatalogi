<?php

/**
 * Raised when the term engine refuses an operation on a statutory term.
 *
 * A refusal is not a failure to reach the engine: a second extension of a term
 * the law allows to be extended once is refused on purpose, and a caller has to
 * be able to tell that apart from the engine being unavailable.
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-is-extended-once-and-a-second-extension-is-refused-req-wri-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DomainException;

/**
 * The term engine refused this operation, and said why.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-is-extended-once-and-a-second-extension-is-refused-req-wri-003
 */
class TermRefusedException extends DomainException {
}//end class
