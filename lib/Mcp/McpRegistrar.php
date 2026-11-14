<?php

/**
 * Registers the scannable MCP services of OpenCatalogi.
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
 * Registers OpenCatalogi's scannable MCP services.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 */
class McpRegistrar {

	/**
	 * Register OpenCatalogiScannableServices, so OpenRegister's AttributeToolScanner
	 * finds the curated `#[McpTool]` methods on PublicationService.
	 *
	 * OpenCatalogi ships no hand-written tool provider (ADR-063): the read tools over
	 * its schemas are derived by OpenRegister from the `x-openregister-mcp`
	 * declarations, and a hand-written provider would shadow them.
	 *
	 * The alias key 'OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi' is the
	 * format OpenRegister enumerates per app. It stays a quoted string, so it is never
	 * resolved as a class at register() time.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::opencatalogi',
			OpenCatalogiScannableServices::class
		);

	}//end register()
}//end class
