<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\IMcpScannableServices.
 *
 * Mirrors the interface on openregister main. Used only in environments where
 * the openregister runtime is not installed (e.g. bare CI containers).
 *
 * This file is loaded by tests/bootstrap-unit.php when the real interface is absent.
 * It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

if (interface_exists(IMcpScannableServices::class) === false) {
	/**
	 * Stub interface for IMcpScannableServices — used only in standalone unit tests.
	 */
	interface IMcpScannableServices {
		/**
		 * The app's own service classes eligible for `#[McpTool]` reflection.
		 *
		 * @return list<class-string>
		 */
		public function getScannableServiceClasses(): array;
	}
}
