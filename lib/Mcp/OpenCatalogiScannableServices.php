<?php

/**
 * OpenCatalogi scannable MCP services
 *
 * Lists the OpenCatalogi service classes whose `#[McpTool]` methods
 * OpenRegister's AttributeToolScanner discovers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Mcp
 * @package  OCA\OpenCatalogi\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * The service classes carrying OpenCatalogi's curated `#[McpTool]` methods:
 * `PublicationService` (searchCatalogPublications, listPublicationFiles).
 * Everything else an agent reads is derived by OpenRegister from the
 * `x-openregister-mcp` declarations in the publication register.
 */
class OpenCatalogiScannableServices implements IMcpScannableServices {

	/**
	 * Returns OpenCatalogi's `#[McpTool]`-attributed service classes.
	 *
	 * @return list<class-string>
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 */
	public function getScannableServiceClasses(): array {
		return [PublicationService::class];

	}//end getScannableServiceClasses()
}//end class
