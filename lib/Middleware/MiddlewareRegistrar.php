<?php

/**
 * Registers the middleware of OpenCatalogi that guards the handed-over records.
 *
 * @category Middleware
 * @package  OCA\OpenCatalogi\Middleware
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the hand-over middleware: the Woo request routes answer 404
 * without dossiq, and 410 on a change once the requests are read-only
 * (woo-request-intake-hands-over-to-dossiq, REQ-WHD-004 and REQ-WHD-005).
 *
 * @spec exclude Component wiring; registers an existing class, no behaviour of its own.
 */
class MiddlewareRegistrar {

	/**
	 * Register the middleware.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; registers an existing class, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(HandoverMiddleware::class);
	}//end register()
}//end class
