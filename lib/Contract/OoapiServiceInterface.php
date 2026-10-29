<?php

/**
 * Contract of OoapiService, for callers in other components.
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
 * Contract of OoapiService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface OoapiServiceInterface {
	/**
	 * Default page size when the caller does not specify one.
	 *
	 * @var integer
	 */
	public const DEFAULT_PAGE_SIZE = 50;

	/**
	 * Determine whether OOAPI 5.0 publication is enabled for a catalog.
	 *
	 * @param array<string, mixed> $catalog The catalog object (jsonSerialize shape).
	 *
	 * @return boolean True when the catalog's `hasOoapi` flag is set.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function isOoapiEnabled(array $catalog): bool;

	/**
	 * Build the absolute per-catalog OOAPI 5.0 base endpoint URL.
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return string The absolute OOAPI v5 base URL.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-federation-directory-advertises-the-ooapi-endpoint-ooapi-009
	 */
	public function endpointUrl(string $catalogSlug): string;

	/**
	 * Determine whether an authenticated Nextcloud user is on the OOAPI-008
	 * consumer allowlist.
	 *
	 * MVP consumer-credential gate (design.md D3): the caller MUST already be
	 * an authenticated Nextcloud user (checked by the controller before this
	 * method is reached — OOAPI-008's "not anonymous-public" core requirement).
	 * When the `ooapi_consumers` config key is empty (the default) every
	 * authenticated user is allowed. When it carries a comma-separated list of
	 * Nextcloud usernames, only those accounts are allowed — this is the
	 * "issuance/revocation" lever an admin uses (OOAPI-010): add/remove a
	 * username to grant/revoke access, the underlying credential being that
	 * account's own Nextcloud app-password (no bespoke token store, D3).
	 *
	 * Per-catalog credential scoping (design.md open question 3) is explicitly
	 * NOT implemented — the allowlist is instance-wide, not per catalog.
	 *
	 * @param string $username The authenticated Nextcloud username.
	 *
	 * @return boolean True when the user is allowed to read OOAPI feeds.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-consumer-credential-authenticated-access-ooapi-008
	 */
	public function isConsumerAllowed(string $username): bool;

	/**
	 * Render the catalog's owning Organisation as an OOAPI 5.0 `organization`
	 * resource, live, with no materialization step (OOAPI-002).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $organizationRegister The resolved organisation register id.
	 * @param string $organizationSchema The resolved organisation schema id.
	 *
	 * @return array<string, mixed>|null The rendered `organization` resource, or null when the catalog has none.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
	 */
	public function renderOrganization(array $catalog, string $organizationRegister, string $organizationSchema): ?array;

	/**
	 * Fetch and render a single `organization` resource by id, constrained to
	 * the catalog's own owning Organisation (D1/D4 — no bespoke query layer,
	 * reuse the existing single `catalog.organization` reference).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $id The requested organization id.
	 * @param string $organizationRegister The resolved organisation register id.
	 * @param string $organizationSchema The resolved organisation schema id.
	 *
	 * @return array<string, mixed>|null The rendered `organization` resource, or null when unresolvable.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
	 */
	public function organizationById(array $catalog, string $id, string $organizationRegister, string $organizationSchema): ?array;

	/**
	 * List the materialized `course` resources scoped to a catalog (OOAPI-001).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $register The resolved `ooapi_courses` register id.
	 * @param string $schema The resolved `ooapi_courses` schema id.
	 * @param int $page The 1-based page number.
	 * @param int $pageSize The requested page size.
	 *
	 * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated course collection.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function listCourses(array $catalog, string $register, string $schema, int $page, int $pageSize): array;

	/**
	 * List the materialized `program` resources scoped to a catalog (OOAPI-001).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $register The resolved `ooapi_programs` register id.
	 * @param string $schema The resolved `ooapi_programs` schema id.
	 * @param int $page The 1-based page number.
	 * @param int $pageSize The requested page size.
	 *
	 * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated program collection.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function listPrograms(array $catalog, string $register, string $schema, int $page, int $pageSize): array;

	/**
	 * List the materialized `offering` resources scoped to a catalog, optionally
	 * filtered to a single parent course (OOAPI-006).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $register The resolved `ooapi_offerings` register id.
	 * @param string $schema The resolved `ooapi_offerings` schema id.
	 * @param string|null $courseId Filter to this parent course's offerings, or null for all.
	 * @param int $page The 1-based page number.
	 * @param int $pageSize The requested page size.
	 *
	 * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated offering collection.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-offerings-nest-under-their-course-ooapi-006
	 */
	public function listOfferings(array $catalog, string $register, string $schema, ?string $courseId, int $page, int $pageSize): array;

	/**
	 * Fetch and render a single materialized resource (`course`/`program`/`offering`)
	 * by id, scoped to the catalog. Returns null when the object does not exist, is
	 * not scoped to this catalog, or its schema carries no `x-ooapi` annotation.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $register The resolved register id.
	 * @param string $schema The resolved schema id.
	 * @param string $id The requested object id.
	 * @param string $idField The OOAPI resource id field name.
	 *
	 * @return array<string, mixed>|null The rendered resource, or null.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
	 */
	public function getResource(array $catalog, string $register, string $schema, string $id, string $idField): ?array;

	/**
	 * Validate a catalog's OOAPI feed against a minimal mandatory-property
	 * checklist. Advisory only — never gates serving (mirrors DCAT-010's
	 * precedent, OOAPI-010).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $courseRegister The resolved `ooapi_courses` register id.
	 * @param string $courseSchema The resolved `ooapi_courses` schema id.
	 *
	 * @return array<int, array<string, mixed>> One entry per violating course resource.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
	 */
	public function validateCatalog(array $catalog, string $courseRegister, string $courseSchema): array;
}//end interface
