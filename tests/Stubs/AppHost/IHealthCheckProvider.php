<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\IHealthCheckProvider.
 *
 * Mirrors the interface shipped by the OpenRegister AppHost observability
 * engine (ADR-040). Used only in environments where the openregister runtime
 * is not installed (e.g. bare CI containers). SearchBackendHealthProvider
 * implements the real interface in production; this stub keeps the class
 * loadable when openregister is absent.
 *
 * This file is loaded by tests/bootstrap*.php when the real interface is absent.
 * It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

if (interface_exists(IHealthCheckProvider::class) === false) {
	/**
	 * Imperative health-check provider for the AppHost observability engine.
	 */
	interface IHealthCheckProvider {

		/**
		 * Run the provider's checks.
		 *
		 * @return array<string, array{ok: bool, severity?: string, message?: string}>
		 */
		public function check(): array;
	}//end interface
}
