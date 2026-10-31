<?php

/**
 * Registers the domain metrics provider of OpenCatalogi.
 *
 * @category Observability
 * @package  OCA\OpenCatalogi\Observability
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

namespace OCA\OpenCatalogi\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the domain metrics provider of OpenCatalogi.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 */
class ObservabilityRegistrar {

	/**
	 * Register the domain-metrics escape hatch under the ADR-035 alias.
	 *
	 * The engine's ProviderMetricSource enumerates this alias. The {kind:provider}
	 * metric descriptor in the manifest merges this provider's samples into the
	 * /api/metrics response.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds an existing provider, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			IMetricsProvider::class . '::opencatalogi',
			OpenCatalogiMetricsProvider::class
		);

	}//end register()
}//end class
