<?php

/**
 * Stub for OCA\OpenRegister\Mcp\IMcpScannableServices.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
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

/**
 * Stub interface: the per-app list of `#[McpTool]`-attributed service classes.
 */
interface IMcpScannableServices {

	/**
	 * The app's own service classes eligible for `#[McpTool]` reflection.
	 *
	 * @return list<class-string>
	 */
	public function getScannableServiceClasses(): array;
}//end interface
