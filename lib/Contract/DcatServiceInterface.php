<?php

/**
 * Contract of DcatService, for callers in other components.
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
 * Contract of DcatService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DcatServiceInterface {
	/**
	 * Determine whether DCAT is enabled for a catalog.
	 *
	 * @param array<string, mixed> $catalog The catalog object (jsonSerialize shape).
	 *
	 * @return boolean True when the catalog's `hasDcat` flag is set.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function isDcatEnabled(array $catalog): bool;

	/**
	 * Resolve the catalog-level DCAT defaults (publisher/license/contactPoint).
	 *
	 * Reads per-catalog overrides from the catalog object, falling back to the
	 * instance-level admin-settings defaults stored in app config.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return array<string, mixed> The resolved defaults (publisherName/publisherUri/
	 *                              license/contactPoint/organisation).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	public function resolveDefaults(array $catalog): array;

	/**
	 * Build the absolute per-catalog DCAT endpoint URL.
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return string The absolute DCAT endpoint URL.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-federation-directory-advertises-the-dcat-endpoint-dcat-009
	 */
	public function catalogEndpointUrl(string $catalogSlug): string;

	/**
	 * Build the canonical public dataset IRI for a publication (PUB-002 URL).
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $uuid The publication UUID.
	 *
	 * @return string The stable dataset IRI.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function datasetIri(string $catalogSlug, string $uuid): string;

	/**
	 * Build the per-catalog DCAT-AP-NL document for one page of datasets.
	 *
	 * Datasets are selected via OR object search scoped to the catalog's
	 * registers/schemas with `_rbac: true` — the PUB-001/WOO-001 visibility rule
	 * (only publicly visible objects appear). Opted-out schemas
	 * (`"x-dcat": false`) are skipped.
	 *
	 * The CALLER guards the catalog first. For the public feed that is
	 * {@see \OCA\OpenCatalogi\Controller\DcatController::catalog()}, which runs it
	 * through
	 * {@see \OCA\OpenCatalogi\Service\PublicationQueryService::applyCatalogReadRuleGuard()}
	 * — the SCH-PFTS-CAT-002 guard `/api/{catalogSlug}` has applied since WOO-580
	 * — so an anonymous harvester never reaches a schema without
	 * `authorization.read` rules (WOO-581). This method does NOT guard on its own:
	 * the admin-only validate/donlReport routes and StatsController::quality call
	 * it too and keep session RBAC. A new public caller must guard the catalog
	 * before handing it in.
	 *
	 * An empty schema list — nothing configured, or everything dropped by that
	 * guard — yields a valid document with zero datasets, never a register-wide
	 * search.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 * @param integer $page The 1-based page number.
	 * @param array<int, array<string, mixed>> $violations Controlled-vocabulary violations collected here (by reference).
	 *
	 * @return array<string, mixed> The JSON-LD document plus `_meta` (lastModified, etag, count).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-only-publicly-visible-objects-appear-in-the-feed-dcat-003
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
	 */
	public function buildCatalogDocument(array $catalog, string $catalogSlug, int $page = 1, array &$violations = []): array;

	/**
	 * Build the instance-level DCAT document listing every DCAT-enabled catalog.
	 *
	 * @return array<string, mixed> The JSON-LD document plus `_meta`.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
	 */
	public function buildInstanceDocument(): array;

	/**
	 * Validate a catalog's feed against the DCAT-AP-NL mandatory-property checklist.
	 *
	 * Advisory only — never gates serving. Reports the dataset IRIs that lack any
	 * resolvable mandatory property (title, publisher).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<int, array<string, mixed>> One entry per violating dataset.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function validateCatalog(array $catalog, string $catalogSlug): array;

	/**
	 * The canonical instance-level harvest-source URL for data.overheid.nl (DONL).
	 *
	 * @return string The `…/api/dcat` harvest-source URL.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function harvestSourceUrl(): string;

	/**
	 * Validate a catalog's feed against the data.overheid.nl (DONL) rule-set.
	 *
	 * Advisory only — never gates serving (DCAT-010 / DCAT-NPF-001). Reports the
	 * canonical harvest-source URL, per-dataset mandatory-property gaps, and the
	 * per-dataset controlled-theme violations collected during rendering (unresolved
	 * `dcat:theme` values that were omitted rather than leaked, DCAT-NPF-003).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array{sourceUrl: string, valid: bool, violations: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function validateForDonl(array $catalog, string $catalogSlug): array;

	/**
	 * Determine the DCAT-AP-NL mandatory properties missing from a dataset node.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 *
	 * @return array<int, string> The missing mandatory property CURIEs.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function mandatoryViolations(array $node): array;

	/**
	 * Fetch all DCAT-enabled catalogs on the instance.
	 *
	 * @return array<int, array<string, mixed>> The DCAT-enabled catalog objects.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
	 */
	public function getDcatEnabledCatalogs(): array;
}//end interface
