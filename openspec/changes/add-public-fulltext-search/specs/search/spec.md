## ADDED Requirements

### Requirement: Public full-text search endpoint absorbs the admin-only search (SCH-PFTS-001)

The existing internal endpoint `GET /apps/opencatalogi/api/search` (currently served by `SearchController::index` and admin-only — returns 401 for anonymous callers) MUST become the canonical public full-text search endpoint. The route MUST be reachable without authentication (annotated `#[PublicPage]` + `#[NoCSRFRequired]`), and MUST NOT require a session user. A new endpoint path MUST NOT be introduced for this purpose.

The companion `GET /publications` endpoint MUST remain unchanged: its behaviour, response shape, and admission rules are out of scope for this change.

#### Scenario: anonymous caller reaches the public search endpoint

- **GIVEN** no authenticated session,
- **WHEN** a `GET /apps/opencatalogi/api/search?q=jaarverslag` request is issued,
- **THEN** the endpoint MUST respond with HTTP 200 and a search-result envelope,
- **AND** MUST NOT respond with HTTP 401.

#### Scenario: the publications endpoint is untouched

- **GIVEN** this change has been implemented,
- **WHEN** the existing `GET /publications` behaviour is exercised against the previous contract,
- **THEN** every previously-passing assertion against `/publications` MUST still pass,
- **AND** no request shape, response shape, or admission rule of `/publications` MUST have changed.

### Requirement: Search results are a flat envelope with `@self.schema` as the row discriminator (SCH-PFTS-002)

`GET /apps/opencatalogi/api/search` MUST return a single flat result array whose rows MAY mix object types. Each row MUST carry an `@self.schema` field whose value identifies the row's schema slug (`publication`, `document`, …); callers use this field as the discriminator to render row-specific UI. Rows MUST NOT be grouped into per-type sub-arrays in the response envelope.

This shape is consistent with the existing `/publications` envelope's use of `@self` metadata; clients MUST be able to switch rendering on `@self.schema` without inspecting any other field.

#### Scenario: mixed-type rows are returned in a single flat array

- **GIVEN** a search query matching both publications and documents,
- **WHEN** the response is inspected,
- **THEN** the result rows MUST live in a single flat array,
- **AND** each row MUST carry `@self.schema` set to the row's schema slug,
- **AND** the response MUST NOT contain separate `publications` / `documents` sub-arrays.

### Requirement: Document rows include an embedded publication summary (SCH-PFTS-003)

Every document row in the result envelope MUST include an embedded `publication` object with at least the fields `{ id, slug, title }` of the publication the document is linked to. Field name is English (`title`, not Dutch `titel`) to match the publication schema's canonical property names in the bundled publication register and to satisfy ADR-001's "do not hardcode Dutch field names as primary" rule. This enables the frontend to render a document card with a "from publication X" backlink without a second API roundtrip. A document with no linked publication MUST NOT appear in the public result set.

#### Scenario: document row carries publication summary

- **GIVEN** a document linked to a publication titled "Jaarverslag 2024" (slug `jaarverslag-2024`, id `pub-001`),
- **WHEN** that document matches the search query,
- **THEN** its result row MUST include `publication: { id: "pub-001", slug: "jaarverslag-2024", title: "Jaarverslag 2024" }`.

#### Scenario: document with no publication link is suppressed

- **GIVEN** a document object that has no linked publication,
- **WHEN** the public search assembly runs,
- **THEN** the document MUST NOT appear in the result rows returned to anonymous callers.

### Requirement: Anonymous visibility filter runs AFTER scoring and merging (SCH-PFTS-004)

For anonymous callers, the public search endpoint MUST apply the same `isObjectPublic()` filter that `/publications` already uses, with identical ordering semantics: the filter runs AFTER the underlying search (scoring and merge) has produced the candidate result set, NOT as a pre-filter on the query. This guarantees the public result set is a strict subset of what scoring produced, and that ranking decisions are made on the full corpus before visibility is enforced.

For documents, visibility MUST also be transitively gated: a document is visible to anonymous callers only if its linked publication itself satisfies `isObjectPublic()`.

#### Scenario: anonymous filter strips depublished publications post-scoring

- **GIVEN** anonymous request matching publications A (live) and B (depublicatiedatum in the past),
- **WHEN** the public search runs,
- **THEN** the candidate set initially contains both A and B,
- **AND** the visibility filter is applied AFTER scoring/merge,
- **AND** the response contains A only.

#### Scenario: document visibility is transitively gated

- **GIVEN** a document D linked to a publication P whose `depublicatiedatum` is in the past,
- **WHEN** an anonymous caller searches for content matching D,
- **THEN** D MUST NOT appear in the response.

### Requirement: A dedicated `document` schema is bundled in the publication register (SCH-PFTS-005)

OpenCatalogi MUST add a new `document` schema as a **bundled** schema inside `lib/Settings/publication_register.json` (registered alongside `publication`, `catalog`, `organization`, …). This schema MUST NOT be supplied by a deployer-side fragment; bundling guarantees the schema is present on every install so the public search endpoint can rely on its presence.

The schema MUST be discoverable through OR's standard schema-listing APIs (i.e. it MUST carry its own `@self.schema` identity in returned objects), MUST be `searchable: true`, and MUST be authorized so that anonymous read access is allowed for documents whose linked publication satisfies `isObjectPublic()` (matching publication's authorization shape).

#### Scenario: document schema ships with the app

- **GIVEN** a fresh OpenCatalogi install,
- **WHEN** the publication register is loaded,
- **THEN** a `document` schema MUST be present under `components.schemas.document` in `lib/Settings/publication_register.json`,
- **AND** the schema MUST carry `searchable: true`,
- **AND** the magic mapper MUST auto-allocate a dedicated table for the schema on first install (`oc_openregister_table_publication_document`, per the magic-mapper's `oc_openregister_table_{register}_{schema}` convention — no manual `configuration.schemas` wiring needed, consistent with how the other bundled schemas — `publication`, `catalog`, `page`, `menu`, `theme`, `glossary`, `listing`, `organization`, `usageCounter` — are wired today).

#### Scenario: document objects carry their own `@self.schema`

- **GIVEN** a stored document object,
- **WHEN** the public search endpoint returns that object,
- **THEN** its row MUST carry `@self.schema = "document"`.

### Requirement: OpenCatalogi consumes OR for search and (Path A) content extraction (SCH-PFTS-006)

OpenCatalogi MUST NOT re-implement search query parsing, faceting, ranking, or document content extraction. The public search endpoint orchestrates: it calls OR's `zoeken-filteren` (per ADR-022 and consistent with `SCH-OR-001` / `SCH-OR-002`) across the publication and document schemas, applies the anonymous visibility filter post-merge (per `SCH-PFTS-004`), shapes the flat envelope (per `SCH-PFTS-002` / `SCH-PFTS-003`), and returns it.

When document content indexing is enabled (Path A — pending Ruben's confirmation per the proposal's "Pending decisions"), OpenCatalogi MUST consume OR's `TextExtractionService` + `FileHandler` + Solr-pipeline; OpenCatalogi MUST NOT add its own extraction or indexing pipeline. When Path A is not yet enabled (Path B), document rows MUST surface metadata-only matches (filename, MIME, linked-publication fields) and content-search MUST be the subject of a separate follow-up change.

#### Scenario: search query parsing is delegated to OR

- **GIVEN** a search request to `/apps/opencatalogi/api/search`,
- **WHEN** the controller runs,
- **THEN** it MUST forward the query to OR's `zoeken-filteren`,
- **AND** MUST NOT re-parse bracket notation, recompute scores, or build a bespoke search filter set.

#### Scenario: document content extraction (Path A) is delegated to OR

- **GIVEN** Path A is enabled,
- **WHEN** a document is indexed for content search,
- **THEN** the extraction pipeline MUST be OR's `TextExtractionService` + `FileHandler` + Solr-pipeline,
- **AND** OpenCatalogi MUST NOT add a parallel extraction pipeline.

### Requirement: Search matches across all schema properties, not only pre-configured ones (SCH-PFTS-007)

Matches on the public search endpoint MUST cover **every searchable property** of the target schemas (`publication`, `document`), plus the standard `@self` metadata fields OR's `zoeken-filteren` already surfaces (`_name`, `_description`, `_summary`, timestamps). Callers MUST NOT need to enumerate which properties are searched, and OpenCatalogi MUST NOT filter the OR-side search surface down to a subset of properties. This makes the behaviour explicit rather than leaving it implicit-in-`zoeken-filteren`-delegation, so a reviewer inspecting only this spec can confirm the WOO-506 requirement that the endpoint zoekt "over alle properties en metadata — niet alleen de schema-properties die wij hebben ingesteld".

New properties added to a schema in future changes (e.g. a `kenmerk` field added to `document` in a later B3) MUST be automatically covered by search without any modification to `SearchController::index` or the assembly helper — they inherit `searchable: true` from the schema-level flag.

#### Scenario: a new schema property is searchable without controller changes

- **GIVEN** the `document` schema gains a new string property `kenmerk` in a future change,
- **AND** the schema keeps `searchable: true`,
- **WHEN** a search query matches the value of `kenmerk` on a document object,
- **THEN** that document MUST appear in the result set,
- **AND** neither `SearchController::index` nor the assembly helper MUST have been modified to expose the new property to search.

#### Scenario: search covers metadata fields OR already surfaces

- **GIVEN** a document whose `@self` metadata contains a match for the search query but whose declared schema properties do not,
- **WHEN** the public search runs,
- **THEN** the document MUST appear in the result set (matched via OR's `zoeken-filteren` on the metadata fields it already covers).
