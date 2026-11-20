<?php

/**
 * Contract of MetadataSuggestionQueue, for callers in other components.
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
 * Contract of MetadataSuggestionQueue, for callers in other components.
 *
 * Callers outside BackgroundJob/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */
interface MetadataSuggestionQueueInterface {
	/**
	 * Queue the suggestions for one created publication.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 * @param string $id       The publication's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function queue(string $register, string $schema, string $id): void;
}//end interface
