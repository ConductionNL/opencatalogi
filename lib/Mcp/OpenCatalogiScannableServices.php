<?php

/**
 * OpenCatalogi's opt-in to OpenRegister's `#[McpTool]` scanner.
 *
 * Registered under the `OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi`
 * container alias. OpenRegister reflects the classes listed here for
 * `#[McpTool]`-attributed methods and serves them as `opencatalogi.<name>`
 * tools. The schema tools (`opencatalogi.<schema>.search|get`) come from the
 * `x-openregister-mcp` blocks in the register, not from here.
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
 *
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Mcp;

use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Mcp\IMcpScannableServices;

/**
 * Lists the OpenCatalogi services that carry `#[McpTool]` methods.
 *
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
 */
class OpenCatalogiScannableServices implements IMcpScannableServices {

	/**
	 * The service classes OpenRegister scans for `#[McpTool]` methods.
	 *
	 * @return list<class-string> PublicationService, which carries
	 *                            `searchCatalogPublications` and `listPublicationFiles`.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 */
	public function getScannableServiceClasses(): array {
		return [PublicationService::class];
	}//end getScannableServiceClasses()
}//end class
