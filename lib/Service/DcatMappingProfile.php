<?php

/**
 * How a catalog maps its publications to DCAT datasets.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * The resolved property map, the catalog-level defaults and the HVD settings
 * that apply to the publications of one schema.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
 */
final class DcatMappingProfile {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $mapping The resolved DCAT property map.
	 * @param array<string, mixed> $defaults Catalog-level publisher/license/contactPoint defaults.
	 * @param array<string, mixed>|null $hvd The schema `x-dcat.hvd` block, or null.
	 * @param string|null $catalogHvdDefault The catalog default HVD category, or null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	public function __construct(
		public readonly array $mapping,
		public readonly array $defaults = [],
		public readonly ?array $hvd = null,
		public readonly ?string $catalogHvdDefault = null,
	) {
	}//end __construct()
}//end class
