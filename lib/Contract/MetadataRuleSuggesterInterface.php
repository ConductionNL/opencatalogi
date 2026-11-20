<?php

/**
 * Contract of MetadataSuggestionService, for callers in other components.
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
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of MetadataSuggestionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */
interface MetadataRuleSuggesterInterface {
	/**
	 * The source of a suggestion Hermiq made.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public const SOURCE_HERMIQ = 'hermiq';

	/**
	 * The proposals the rules floor makes for the empty fields of a publication.
	 *
	 * @param array<string, mixed> $publication The publication as stored.
	 * @param array<string, mixed> $context     What the instance knows around the publication.
	 *
	 * @return array<int, array{field: string, value: string, valueLabel: string, source: string, reason: string}> The proposals.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function suggestFromRules(array $publication, array $context=[]): array;
}//end interface
