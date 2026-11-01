<?php

/**
 * Contract of PublishedCollectionsService, for callers in other components.
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


/**
 * Contract of PublishedCollectionsService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublishedCollectionsServiceInterface {
	/**
	 * The configured published collections.
	 *
	 * @return array<int, array<string, mixed>> The collections.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the stored configuration is not readable JSON.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function collections(): array;

	/**
	 * Save the published collections, after checking every rule on them.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return array{saved: boolean, errors: array<int, string>}
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function save(array $collections): array;

	/**
	 * Whether a record publishes under the configured set.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return boolean True when a configured collection publishes it.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function publishes(array $record): bool;
}//end interface
