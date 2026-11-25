<?php

/**
 * Stub for OCA\OpenRegister\Mcp\Attribute\McpTool.
 *
 * Read by PHPStan only (phpstan.neon scanFiles): OpenRegister is not in this app's vendor tree, and
 * PublicationAgentTools and PublicationService mark their agent tools with this attribute.
 * The constructor mirrors the real attribute on openregister main (lib/Mcp/Attribute/McpTool.php).
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

namespace OCA\OpenRegister\Mcp\Attribute;

use Attribute;

/**
 * Marks a method as an MCP tool OpenRegister exposes to agents.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpTool {

	/**
	 * The tool's metadata, as the real attribute takes it.
	 *
	 * @param string|null          $name            The tool name.
	 * @param string|null          $description     What the tool does.
	 * @param bool|null            $readOnlyHint    Whether the tool only reads.
	 * @param bool|null            $destructiveHint Whether the tool can destroy data.
	 * @param bool|null            $idempotentHint  Whether repeating the call changes nothing.
	 * @param string|null          $scope           The scope the tool needs.
	 * @param string|null          $subject         The subject the tool acts on.
	 * @param string|null          $action          The action on that subject.
	 * @param string|null          $reach           How far the tool reaches.
	 * @param array<string, mixed> $annotations     Extra MCP annotations.
	 */
	public function __construct(
		public readonly string|null $name = null,
		public readonly string|null $description = null,
		public readonly bool|null $readOnlyHint = null,
		public readonly bool|null $destructiveHint = null,
		public readonly bool|null $idempotentHint = null,
		public readonly string|null $scope = null,
		public readonly string|null $subject = null,
		public readonly string|null $action = null,
		public readonly string|null $reach = null,
		public readonly array $annotations = [],
	) {
	}//end __construct()
}//end class
