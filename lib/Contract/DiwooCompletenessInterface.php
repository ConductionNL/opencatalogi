<?php

/**
 * Contract of the DiWoo completeness check, for callers in other components.
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
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-woo-publication-without-the-metadata-the-woo-index-requires-does-not-go-public-req-dwp-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Which mandatory DiWoo elements a publication cannot fill (implemented by SitemapDocumentMapper,
 * so a publication it accepts is one the sitemap can emit).
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DiwooCompletenessInterface {
	/**
	 * The mandatory DiWoo elements the publication cannot fill, keyed by the field an editor sets.
	 *
	 * @param array<string, mixed> $publication The publication, with `@self.organisation`.
	 *
	 * @return array<string, string> The missing fields and why, empty when complete.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-woo-publication-without-the-metadata-the-woo-index-requires-does-not-go-public-req-dwp-003
	 */
	public function mandatoryGaps(array $publication): array;
}//end interface
