<?php

/**
 * Contract of WooRequestStore, for callers in other components.
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
 * Contract of WooRequestStore, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface InformationRequestStoreInterface {
	/**
	 * Write a request.
	 *
	 * @param array<string, mixed> $record The request.
	 * @param string $uuid The id to write to, empty for a new one.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RuntimeException When the register is unresolvable or the write fails.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function save(array $record, string $uuid = ''): array;

	/**
	 * Read one request, or null when there is no such request.
	 *
	 * @param string $requestId The request.
	 *
	 * @return array<string, mixed>|null The request.
	 *
	 * @throws RuntimeException When the register is unresolvable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function find(string $requestId): ?array;

	/**
	 * Every stored request.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @throws RuntimeException When the register is unresolvable or unreadable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function all(): array;
}//end interface
