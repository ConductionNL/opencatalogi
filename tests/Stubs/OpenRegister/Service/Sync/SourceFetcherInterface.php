<?php

/**
 * Stub for OCA\OpenRegister\Service\Sync\SourceFetcherInterface.
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

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * Stub of the harvest fetcher contract; mirrors the real signatures.
 */
interface SourceFetcherInterface {
	/**
	 * Whether the fetcher serves the type.
	 *
	 * @param string $type The source type.
	 *
	 * @return bool
	 */
	public function supports(string $type): bool;

	/**
	 * The external ids changed since the checkpoint.
	 *
	 * @param Source      $source The source.
	 * @param string|null $since  The checkpoint.
	 *
	 * @return array<int, string>
	 */
	public function gather(Source $source, ?string $since = null): array;

	/**
	 * One item.
	 *
	 * @param Source $source     The source.
	 * @param string $externalId The item.
	 *
	 * @return array<string, mixed>
	 */
	public function fetch(Source $source, string $externalId): array;

	/**
	 * The source type.
	 *
	 * @return string
	 */
	public function getType(): string;

	/**
	 * The display name.
	 *
	 * @return string
	 */
	public function getDisplayName(): string;

	/**
	 * The config schema.
	 *
	 * @return array<string, mixed>
	 */
	public function getConfigSchema(): array;
}//end interface
