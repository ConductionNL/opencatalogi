<?php

/**
 * Contract of PublicationQueryService, for callers in other components.
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
 * Contract of PublicationQueryService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationQueryServiceInterface {
	/**
	 * Assemble the public full-text search result envelope (SCH-PFTS-001,
	 * SCH-PFTS-CAT-001..003).
	 *
	 * Delegates entirely to OR's `ObjectService::searchObjectsPaginated` across the
	 * (register × schema) set derived from the catalog scope. Matches from every
	 * schema in every catalog the caller may see are merged into a single flat array
	 * discriminated by `@self.schema` slug, resolved dynamically via SchemaMapper. A
	 * document row's linked publication is embedded via a per-document
	 * `_relations_contains` refinement; documents whose linked publication is not
	 * publicly visible are dropped (transitive visibility).
	 *
	 * Visibility is enforced in SQL by OR's schema-level RBAC, evaluated as an
	 * anonymous caller for every session (SCH-PFTS-001): the read runs inside OR's
	 * `runAsAnonymous()`, so only `public`-group read rules decide what comes back
	 * and a signed-in administrator sees exactly what an anonymous caller sees.
	 * See the Stap 1 comment inside this method (WOO-578; the interim WOO-551
	 * behaviour applies only on an OpenRegister without that primitive).
	 *
	 * Scope resolution:
	 *   1. `_catalog=<slug>` — that catalog's registers + schemas (SCH-PFTS-CAT-001).
	 *   2. `_catalogi[]=…` — union across the resolved catalogs.
	 *   3. no param — union of `listed: true` + `published` catalogs (SCH-PFTS-CAT-002).
	 * A caller's own `_schema`/`_registers`/`fq`/`catalogSlug` cannot widen scope past
	 * the catalog boundary — those are stripped before forwarding to OR.
	 *
	 * Content-search: this ships metadata search across schema properties + `@self`
	 * metadata. When `_content=true` is set, OR's `_content_search` flag is forwarded
	 * to widen matching to document body text (WOO-517 add-on). Extraction is owned
	 * by OR's TextExtractionService + ChunkMapper.
	 *
	 * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
	 *                              Recognised keys: `_content` (opt-in body-text search),
	 *                              `_catalog` (single catalog slug), `_catalogi[]` (multi
	 *                              catalog union).
	 * @param object $objectService OpenRegister ObjectService instance (already resolved from container).
	 *
	 * @return array{results: array<int, array>, total: int} Flat mixed-type result envelope.
	 *
	 * @spec openspec/changes/fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	public function assemblePublicSearchResults(array $queryParams, object $objectService): array;

	/**
	 * Apply the SCH-PFTS-CAT-002 read-rule guard to a catalog's schema scope.
	 *
	 * Same DROP as `/api/search` (WOO-574), on a different surface — but no
	 * longer the same RULE: since WOO-578 that endpoint applies it to every
	 * caller, while this guard is anonymous-only (see ANONYMOUS ONLY below).
	 * Which schemas it removes, and which half of that set is the actual leak
	 * rather than a schema OpenRegister already refuses, is set out on
	 * {@see dropSchemasWithoutReadRules()}.
	 * `assemblePublicSearchResults()` has dropped those schemas since WOO-574,
	 * but that method only serves `/api/search`. The
	 * per-catalog routes (`/api/{catalogSlug}` and its `/{id}`, `/uses`,
	 * `/used`, `/attachments`, `/download` siblings) are `#[PublicPage]` too and
	 * build their scope straight from the catalog, so the guard never reached
	 * them and the leak stayed open there (WOO-580).
	 *
	 * Guarding the CATALOG rather than each query is what makes this one rule
	 * instead of six: every one of those routes reads `$catalog['schemas']`,
	 * directly or through {@see buildCatalogSearchQuery()} /
	 * {@see findObjectInCatalog()}, and each already fails closed on an empty
	 * schema list. Handing them a guarded catalog therefore also keeps the
	 * `@catalog.schemas` block in the response from naming a schema the caller
	 * may not read.
	 *
	 * "THE CATALOG IS GUARDED" IS NOT "EVERY READER OF ITS SCHEMAS IS
	 * GUARDED". This guards the catalog ITS CALLER hands in. Its callers are
	 * the six routes in
	 * {@see \OCA\OpenCatalogi\Controller\PublicationsController} plus, since
	 * WOO-581, `/api/catalogs/{slug}/dcat`
	 * ({@see \OCA\OpenCatalogi\Controller\DcatController::catalog()}) and
	 * `/api/catalogs/{slug}/schema`
	 * ({@see \OCA\OpenCatalogi\Controller\SchemaOrgController::catalog()}).
	 * Readers that build a UNION over every catalog instead of taking one
	 * catalog go through {@see applySchemaScopeReadRuleGuard()}:
	 * `/api/federation/publications` and `/api/catalogi/{id}`. A new reader of
	 * `$catalog['schemas']` on a public route needs one of the two; grep for
	 * the source, not the route family — that is how WOO-580 and WOO-581 were
	 * each found after the previous fix.
	 *
	 * ANONYMOUS ONLY, deliberately — and since WOO-578 that is a DIFFERENCE
	 * from `/api/search`, not a match. That endpoint drops rule-less schemas
	 * unconditionally now, and (where the installed OpenRegister carries the
	 * primitive) evaluates every read inside its `runAsAnonymous()`, so its own
	 * SCH-PFTS-CAT-002 guard holds for signed-in callers too. These per-catalog routes keep session RBAC,
	 * and that is the surface WOO-578 leaves staff when it narrows
	 * `/api/search`; widening the guard here would close that door in the same
	 * move.
	 *
	 * @param array $catalog Catalog data array (keys: schemas, registers).
	 *
	 * @return array The catalog with `schemas` normalised to int[] and, for an
	 *               anonymous caller, filtered to the schemas that carry read rules.
	 *
	 * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	public function applyCatalogReadRuleGuard(array $catalog): array;

	/**
	 * Apply the SCH-PFTS-CAT-002 read-rule guard to a bare schema-id scope.
	 *
	 * The same anonymous-only drop as {@see applyCatalogReadRuleGuard()}, for
	 * the readers that do not hold one catalog but a UNION of the schema lists
	 * of every catalog on the instance (WOO-581):
	 * {@see \OCA\OpenCatalogi\Service\PublicationService::getCatalogFilters()}
	 * (and its fast-path twin), which serve `/api/federation/publications`, and
	 * {@see \OCA\OpenCatalogi\Service\CatalogiService::getCatalogFilters()},
	 * which serves `/api/catalogi/{id}`. Both are `#[PublicPage]`, both searched
	 * with `_rbac: true` over an unguarded union, and on both an anonymous
	 * caller read the full record of a schema without an `authorization` block.
	 * The WOO sitemap ({@see \OCA\OpenCatalogi\Service\SitemapService}) runs
	 * its single category schema through it too. Through getCatalogFilters() it
	 * also covers `/api/federation/publications/{id}` and its `/uses`, `/used`,
	 * `/attachments` and `/download` siblings.
	 *
	 * A signed-in caller gets the list back UNTOUCHED — not even normalised —
	 * so session-RBAC behaviour on these paths does not move. An anonymous
	 * caller gets int ids, with every schema lacking read rules removed (see
	 * {@see dropSchemasWithoutReadRules()} for which of those is the actual
	 * leak). The caller MUST treat an empty result as "nothing to search", not
	 * as "no schema filter".
	 *
	 * @param array $schemaIds Schema ids (int or numeric string) in the scope.
	 *
	 * @return array The scope, filtered for an anonymous caller.
	 *
	 * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	public function applySchemaScopeReadRuleGuard(array $schemaIds): array;

	/**
	 * Read one catalogue the way an anonymous reader reads it, for every caller.
	 *
	 * @param array  $catalog       The catalogue (keys: schemas, registers).
	 * @param array  $queryParams   Paging and ordering for the read; scope keys are stripped.
	 * @param object $objectService OpenRegister ObjectService.
	 *
	 * @return array The OpenRegister result envelope (`results`, `total`).
	 *
	 * @psalm-param   array<string, mixed> $catalog
	 * @psalm-param   array<string, mixed> $queryParams
	 * @phpstan-param array<string, mixed> $catalog
	 * @phpstan-param array<string, mixed> $queryParams
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function readCatalogueAsAnonymous(array $catalog, array $queryParams, object $objectService): array;

	/**
	 * Apply the SCH-PFTS-CAT-002 read-rule guard to the ROWS of a relation result.
	 *
	 * `/api/{catalogSlug}/{id}/uses` and `/used` guard their root object, then hand
	 * off to OpenRegister's relation handler, which loads the related objects from
	 * every register × schema and filters them by schema RBAC only — and RBAC reads
	 * an empty `authorization` block as open (WOO-581 review round 2). Those rows
	 * are legitimately outside the catalog's schema list (a publication's documents
	 * live in the document schema), so catalog membership is the wrong filter; the
	 * leak class is. For an anonymous caller every row whose schema lacks read rules
	 * (or carries no numeric schema id) is dropped. A signed-in caller keeps every
	 * row (and only gets the page cut when `$page` is given).
	 *
	 * `total` needs the whole list. OR's `getUses()` counts every related object and
	 * then slices the page, so lowering `total` by the rows dropped from one page
	 * still counted the hidden rows on the other pages — and walking `_offset`
	 * showed where they sat (WOO-581 review round 3). With `$page` the envelope is
	 * the caller's FULL relation list (fetched with `_offset` 0 and no limit): the
	 * guard runs over all of it, `total` becomes the number of rows kept, and the
	 * page is cut here. Without `$page` — `getUsedBy()`, whose `total` is already
	 * page-local — `total` is lowered by the rows dropped from the page, which is
	 * exact there.
	 *
	 * @param array                                   $result An OpenRegister relation envelope (`results`, `total`, …).
	 * @param array{limit: int, offset: int}|null     $page   The caller's page when `$result` holds the full list.
	 *
	 * @return array The envelope with the guarded rows.
	 *
	 * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	public function applyReadRuleGuardToRows(array $result, ?array $page = null): array;

	/**
	 * Whether the current caller is anonymous (no logged-in user).
	 *
	 * Consumed by {@see \OCA\OpenCatalogi\Controller\PublicationsController::getUses}
	 * and `::getUsedBy` to gate anonymous access to relationship endpoints on
	 * non-public root objects. Fail-closed: treats an unavailable session as
	 * anonymous so the stricter visibility rule applies.
	 *
	 * @return boolean True when no user session is available or no user is logged in.
	 *
	 * @spec exclude Visibility helper for the public relation-endpoint published-predicate guard.
	 */
	public function isAnonymous(): bool;

	/**
	 * Determine whether an object is publicly visible (published and not depublished).
	 *
	 * Mirrors the live OpenRegister RBAC visibility model (APB-006), the same rule
	 * the public publications API and the frontend `publicationStatus` helpers use:
	 * an object is public when its own `publicationDate` field is set and is at or
	 * before "now", and either carries no `depublicationDate` or one still in the
	 * future. The removed object-level `@self.published` predicate is not consulted.
	 *
	 * @param array $objectData The serialized object data (own fields + `@self` envelope).
	 *
	 * @return boolean True when the object is currently published.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	public function isObjectPublic(array $objectData): bool;

	/**
	 * Find the register and schema IDs for an object UUID within a constrained scope.
	 *
	 * Locates which OpenRegister (register × schema) pair holds a given UUID, always
	 * scoped to the caller-supplied register/schema lists. The lookup goes through
	 * OpenRegister's `ObjectService` (ADR-022: consume OR abstractions) rather than
	 * issuing raw SQL against OR's internal per-register/per-schema storage tables or
	 * probing the DBMS catalog for their existence. OR remains free to change its
	 * physical storage layout without breaking opencatalogi.
	 *
	 * The legacy platform-wide search across every magic table is gone (#734) — it was
	 * an anonymous-reachable DoS vector and also leaked cross-catalog objects (#733).
	 * Callers MUST pass non-empty $allowedRegisters and $allowedSchemas; otherwise the
	 * method returns null without touching OpenRegister.
	 *
	 * @param string                 $uuid             The UUID of the object to find.
	 * @param array<int|string>|null $allowedRegisters Register IDs the search may touch.
	 * @param array<int|string>|null $allowedSchemas   Schema IDs the search may touch.
	 *
	 * @return array{register: int, schema: int}|null The register/schema IDs, or null.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	public function findObjectLocation(
		string $uuid,
		?array $allowedRegisters=null,
		?array $allowedSchemas=null
	): ?array;

	/**
	 * Build the ObjectService search query for a catalog index request.
	 *
	 * Merges the incoming request parameters with catalog-level schema/register filters,
	 * handles multi-schema and multi-register cases, and strips non-universal _order fields
	 * when searching across multiple registers.
	 *
	 * @param array  $catalog       Catalog data array (keys: schemas, registers).
	 * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
	 * @param object $objectService ObjectService instance (already resolved from container).
	 *
	 * @return array The merged and sanitised search query ready for searchObjectsPaginated().
	 *
	 * @spec exclude Query-assembly plumbing extracted from PublicationsController; translates
	 *       request params into an ObjectService search query, no domain behavior of its own.
	 */
	public function buildCatalogSearchQuery(array $catalog, array $queryParams, object $objectService): array;

	/**
	 * Resolve schema and register objects from OpenRegister mappers for catalog enrichment.
	 *
	 * Returns an array with keys 'schemas' (id → {id, slug, title}) and
	 * 'registers' (id → {id, slug, title}). Missing entries are silently skipped.
	 *
	 * @param array $catalog Catalog data array (keys: schemas, registers).
	 *
	 * @return array{schemas: array<int|string, array>, registers: array<int|string, array>}
	 *
	 * @spec exclude Metadata-resolution plumbing extracted from PublicationsController; looks up
	 *       schema/register labels via OR mappers for response enrichment, no domain behavior.
	 */
	public function resolveSchemaAndRegisterObjects(array $catalog): array;

	/**
	 * Find an object within a catalog's registers/schemas using ObjectService.
	 *
	 * Iterates over each (register, schema) combination in the catalog.
	 * Returns the first matching object entity, or null if not found.
	 *
	 * @param array  $catalog       Catalog data array (keys: schemas, registers).
	 * @param string $id            The UUID of the object to find.
	 * @param object $objectService ObjectService instance (already resolved from container).
	 *
	 * @return object|null The found object entity, or null.
	 *
	 * @spec exclude Lookup plumbing extracted from PublicationsController; iterates a catalog's
	 *       (register, schema) pairs and delegates the actual read to ObjectService::find().
	 */
	public function findObjectInCatalog(array $catalog, string $id, object $objectService): ?object;

	/**
	 * Recursively strips empty values (null, empty string, empty array) from an array.
	 *
	 * Used to reduce API response payload by omitting properties that have no value.
	 * Values of 0, false, and "0" are preserved as they are meaningful.
	 *
	 * @param array $data The data array to strip empty values from.
	 *
	 * @return array The data with empty values removed.
	 *
	 * @spec exclude Response-shaping plumbing extracted from PublicationsController; recursively
	 *       prunes empty values to slim the payload, no domain behavior.
	 */
	public function stripEmptyValues(array $data): array;
}//end interface
