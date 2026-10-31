<?php

/**
 * Registers the MCP tool provider of OpenCatalogi.
 *
 * @category Mcp
 * @package  OCA\OpenCatalogi\Mcp
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

namespace OCA\OpenCatalogi\Mcp;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the MCP tool provider of OpenCatalogi.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 */
class McpRegistrar {

	/**
	 * Register OpenCatalogiToolProvider as the MCP tool provider for the AI Chat Companion.
	 *
	 * The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::opencatalogi' is the format
	 * that OpenRegister's McpToolsService enumerates to discover per-app providers
	 * (hydra ADR-034/035). It stays a quoted string, so it is never resolved as a class
	 * at register() time.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds an existing provider, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::opencatalogi',
			OpenCatalogiToolProvider::class
		);

	}//end register()
}//end class
