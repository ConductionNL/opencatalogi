<?php

/**
 * Contract of SchemaOrgService, for callers in other components.
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
 * Contract of SchemaOrgService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface SchemaOrgServiceInterface {
	/**
	 * Resolve the schema.org `@type` for a schema from its `x-schema-org` marker.
	 *
	 * Reads the CURIE (`schema:X`) declared on the schema (ADR-048/051) and strips
	 * the `schema:` prefix. Never introduces an app-local marker registry.
	 *
	 * @param integer $schemaId The OpenRegister schema ID.
	 *
	 * @return string The bare schema.org type (e.g. `CreativeWork`), defaulting to `CreativeWork`.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function markerTypeForSchema(int $schemaId): string;

	/**
	 * Whether the catalog elects `schema:Dataset` for its publications.
	 *
	 * Reuses the catalog config surface — a `schemaOrgDataset` boolean on the
	 * catalog object — defaulting off. Google Dataset Search indexes only
	 * `Dataset`, so open-data catalogs opt in.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return boolean True when the catalog elects the Dataset shape.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function isDatasetElected(array $catalog): bool;

	/**
	 * Build the schema.org JSON-LD node for a single publication.
	 *
	 * The `@type` is the publication schema's `x-schema-org` marker, or `Dataset`
	 * when the catalog elects it. When elected, the node carries `distribution`
	 * (`DataDownload` per attachment) and an `includedInDataCatalog` backlink.
	 *
	 * @param array<string, mixed> $object The rendered publication object.
	 * @param array<string, mixed> $catalog The owning catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed> The schema.org JSON-LD node (single document).
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function buildPublicationNode(array $object, array $catalog, string $catalogSlug): array;

	/**
	 * Build the schema.org `DataCatalog` node for a catalog, listing its publicly
	 * visible publications as `dataset` references.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed> The schema.org `DataCatalog` JSON-LD node.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function buildCatalogNode(array $catalog, string $catalogSlug): array;
}//end interface
