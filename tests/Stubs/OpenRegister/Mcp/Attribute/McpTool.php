<?php

/**
 * Stub for OCA\OpenRegister\Mcp\Attribute\McpTool.
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
 * Stub attribute with the same named arguments as OpenRegister's McpTool, so a
 * test can read the declaration through reflection without OpenRegister.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpTool {

	/**
	 * Constructor.
	 *
	 * @param string|null                          $name            Local tool name.
	 * @param string|null                          $description     LLM-facing description.
	 * @param bool|null                            $readOnlyHint    Read-only hint.
	 * @param bool|null                            $destructiveHint Destructive hint.
	 * @param bool|null                            $idempotentHint  Idempotent hint.
	 * @param string|null                          $scope           Advisory scope.
	 * @param string|null                          $subject         The thing acted on.
	 * @param string|null                          $action          The verb performed.
	 * @param string|null                          $reach           The widest reach.
	 * @param array<string, bool|int|float|string> $annotations     Free-form marks.
	 */
	public function __construct(
		public readonly ?string $name=null,
		public readonly ?string $description=null,
		public readonly ?bool $readOnlyHint=null,
		public readonly ?bool $destructiveHint=null,
		public readonly ?bool $idempotentHint=null,
		public readonly ?string $scope=null,
		public readonly ?string $subject=null,
		public readonly ?string $action=null,
		public readonly ?string $reach=null,
		public readonly array $annotations=[],
	) {
	}//end __construct()
}//end class
