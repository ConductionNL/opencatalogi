<?php

/**
 * Registers the middleware of OpenCatalogi.
 *
 * @category Middleware
 * @package  OCA\OpenCatalogi\Middleware
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the app's middleware, in the order the framework runs them,
 * including the hand-over middleware: the Woo request routes answer 404
 * without dossiq, and 410 on a change once the requests are read-only
 * (woo-request-intake-hands-over-to-dossiq, REQ-WHD-004 and REQ-WHD-005).
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
 */
class MiddlewareRegistrar {

	/**
	 * Register the middleware.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// API-Version header and problem details on the JSON API (spec api-documentation).
		$context->registerMiddleware(ApiContractMiddleware::class);

		// Officer responses, and every response on a blocked install, carry noindex (REQ-WAB-003).
		$context->registerMiddleware(NoIndexMiddleware::class);

		// Staging closes every public route to anonymous callers (instance-staging-mode REQ-STG-003).
		$context->registerMiddleware(StagingMiddleware::class);

		// The Woo request routes once dossiq handles them: 404 without dossiq,
		// 410 on a change once they are read-only (REQ-WHD-004, REQ-WHD-005).
		$context->registerMiddleware(HandoverMiddleware::class);
	}//end register()
}//end class
