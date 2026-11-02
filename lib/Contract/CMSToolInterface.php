<?php

/**
 * Contract of CMSTool, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

use OCA\OpenRegister\Db\Agent;

/**
 * Contract of CMSTool, for callers in other components.
 *
 * Callers outside Tool/ depend on this interface instead of on the class.
 */
interface CMSToolInterface {
	/**
	 * Get tool name
	 *
	 * @return string Tool name
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function getName(): string;

	/**
	 * Get tool description
	 *
	 * @return string Tool description
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function getDescription(): string;

	/**
	 * Set agent context
	 *
	 * @param Agent|null $agent Agent entity
	 *
	 * @return void
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function setAgent(?Agent $agent): void;

	/**
	 * Get function definitions
	 *
	 * Returns OpenAI-compatible function definitions for the LLM.
	 *
	 * @return array<array<string, mixed>> Function definitions
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function getFunctions(): array;

	/**
	 * Execute a function
	 *
	 * @param string $functionName Function name
	 * @param array $parameters Function parameters
	 * @param string|null $userId User ID (optional)
	 *
	 * @return array Execution result
	 *
	 * @throws \Exception If function execution fails
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array;

	/**
	 * Create a new page
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function createPage(array $parameters): array;

	/**
	 * List all pages
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function listPages(array $parameters): array;

	/**
	 * Create a new menu with proper schema structure.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function createMenu(array $parameters): array;

	/**
	 * List all menus
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function listMenus(): array;

	/**
	 * Add a menu item to a menu
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function addMenuItem(array $parameters): array;
}//end interface
