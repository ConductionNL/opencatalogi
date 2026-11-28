<?php

/**
 * Contract of HarvestFeedService, for callers in other components.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use RuntimeException;

/**
 * The harvest feeds an administrator lists, saves and deletes.
 *
 * A refusal is a RuntimeException whose code is the HTTP status (403, 404,
 * 422) and which serialises to `{error, field}`.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */
interface HarvestFeedServiceInterface {
	/**
	 * This app's feeds.
	 *
	 * @return array<int, array<string, mixed>> The feeds.
	 *
	 * @throws RuntimeException When the caller is not an administrator.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function list(): array;

	/**
	 * Save a new feed.
	 *
	 * @param array<string, mixed> $data The feed's fields.
	 *
	 * @return array<string, mixed> The stored feed.
	 *
	 * @throws RuntimeException When the caller is not an administrator, or a field is refused.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function create(array $data): array;

	/**
	 * Change a feed.
	 *
	 * @param int                  $id   The feed's source id.
	 * @param array<string, mixed> $data The changed fields.
	 *
	 * @return array<string, mixed> The stored feed.
	 *
	 * @throws RuntimeException When the caller is not an administrator, the feed is not this app's, or a field is refused.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function update(int $id, array $data): array;

	/**
	 * Delete a feed and its flow.
	 *
	 * @param int $id The feed's source id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the caller is not an administrator, or the feed is not this app's.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function delete(int $id): void;
}//end interface
