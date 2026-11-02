<?php

/**
 * Builds the schema.org DataCatalog node of a catalog found by its slug.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/specs/structured-data-discoverability/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;

/**
 * Finds a catalog by its slug, applies the catalog read-rule guard and renders
 * the guarded catalog as a schema.org DataCatalog node.
 *
 * @spec openspec/specs/structured-data-discoverability/spec.md
 */
class SchemaOrgCatalogDocument {

	/**
	 * Constructor.
	 *
	 * @param SchemaOrgServiceInterface        $schemaOrgService The schema.org JSON-LD renderer.
	 * @param CatalogiServiceInterface         $catalogiService  Catalog resolution (slug → catalog).
	 * @param PublicationQueryServiceInterface $queryService     SCH-PFTS-CAT-002 read-rule guard (WOO-581).
	 */
	public function __construct(
		private readonly SchemaOrgServiceInterface $schemaOrgService,
		private readonly CatalogiServiceInterface $catalogiService,
		private readonly PublicationQueryServiceInterface $queryService,
	) {
	}//end __construct()

	/**
	 * The DataCatalog node of the catalog with this slug, or null when no catalog has it.
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed>|null The schema.org node.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function node(string $catalogSlug): ?array {
		$catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
		if ($catalog === null) {
			return null;
		}

		// SCH-PFTS-CAT-002 (WOO-581): same guard as `/api/{catalogSlug}` since
		// WOO-580 — an anonymous caller must not see a schema without
		// `authorization.read` rules listed in this DataCatalog.
		$catalog = $this->queryService->applyCatalogReadRuleGuard(catalog: $catalog);

		return $this->schemaOrgService->buildCatalogNode($catalog, $catalogSlug);
	}//end node()
}//end class
