<?php

/**
 * Contract of PubliccodeHarvestService, for callers in other components.
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

use RuntimeException;

/**
 * Contract of PubliccodeHarvestService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PubliccodeHarvestServiceInterface {
	/**
	 * Everything the admin section shows.
	 *
	 * @return array<string, mixed> The status.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function status(): array;

	/**
	 * Write one integriq synchronization per shard, updating those that exist.
	 *
	 * @return array{created: int, updated: int} What was written.
	 *
	 * @throws RuntimeException When integriq is not installed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function setUp(): array;

	/**
	 * Switch the harvest flow on or off.
	 *
	 * Switching on adopts the flow first, so the administrator who switches it
	 * on becomes its owner. The flow arrives unowned on purpose (OpenRegister's
	 * adoption contract) and an unowned flow never dispatches.
	 *
	 * @param bool $enabled Whether the harvest should run.
	 *
	 * @return array<string, mixed> The flow status after the switch.
	 *
	 * @throws RuntimeException When integriq is missing or the flow was not imported.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function setEnabled(bool $enabled): array;

	/**
	 * Start a run now. The run is queued; the flow engine's worker advances it.
	 *
	 * @return array<string, mixed> The queued run.
	 *
	 * @throws RuntimeException When the harvest is not set up and switched on.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function runNow(): array;

	/**
	 * Read the shard file.
	 *
	 * @return array<string, mixed> The decoded definitions.
	 *
	 * @throws RuntimeException When the file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-002-shards-partition-the-file-sizes-so-no-search-passes-1000-results
	 */
	public function shardDefinitions(): array;
}//end interface
