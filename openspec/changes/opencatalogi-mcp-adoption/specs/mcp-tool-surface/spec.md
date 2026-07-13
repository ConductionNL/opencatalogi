# MCP Tool Surface

OpenCatalogi's agent-facing tool surface, derived by OpenRegister from a per-schema `x-openregister-mcp` declaration (ADR-063), plus two curated `#[McpTool]` service methods for the capabilities derivation cannot supply. OpenCatalogi writes no MCP tool *provider* code.

## ADDED Requirements

### Requirement: Exactly five curated schemas declare the MCP dialect (REQ-001)
OpenCatalogi MUST declare `x-openregister-mcp` on exactly five schemas — `publication`, `catalog`, `document`, `organization`, `theme` — and MUST NOT declare it on any of the other 9 schemas in the register (`page`, `menu`, `glossary`, `listing`, `usageCounter`, and the 4 OOAPI schemas `course`, `program`, `offering`, `organization` in `register.d/ooapi-catalog-publication.json`). The declaration MUST live at `components.schemas.<schema>.configuration["x-openregister-mcp"]` (the path `SchemaDerivedToolProvider` reads via `$schema->getConfiguration()`), alongside the existing `autoPublish` key, and MUST carry `enabled: true`.

#### Scenario: The derived catalogue contains only the curated schemas
- GIVEN the OpenCatalogi register is imported
- WHEN OpenRegister's `SchemaDerivedToolProvider` builds the tool catalogue for app id `opencatalogi`
- THEN it exposes tools for exactly the schemas `publication`, `catalog`, `document`, `organization`, `theme`
- AND no tool exists for `page`, `menu`, `glossary`, `listing`, `usageCounter`, or any OOAPI schema

#### Scenario: The admin-only usage counter derives no tool
- GIVEN `usageCounter.authorization.read` is `["admin"]`
- WHEN the derived catalogue is built
- THEN no `opencatalogi.usageCounter.*` tool is registered
- AND no tool is exposed that would deny for every non-admin caller

### Requirement: The MCP surface is read-only — no write verb is declared (REQ-002)
Every declared verb MUST be `search` or `get`, MUST set `scope: "read"`, and MUST set `readOnlyHint: true`. OpenCatalogi MUST NOT declare `create`, `update`, or `delete` on any schema. Rationale (binding): `publication.authorization.read` is `[{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}, "authenticated"]` and `catalog` carries the same shape on `published` — publication state is an ordinary writable property *inside the RBAC match rule*, so any write verb is a publish verb, and an agent could publish a government record to the public internet by writing one date. `configuration.autoPublish: false` does not mitigate this, because a direct write to `publicatiedatum` does not go through the auto-publish path.

#### Scenario: No derived tool can publish a publication
- GIVEN the derived catalogue for app id `opencatalogi`
- WHEN every tool in it is inspected
- THEN each tool id ends in `.search` or `.get`
- AND no tool id ends in `.create`, `.update`, or `.delete`
- AND no tool exists through which `publicatiedatum` or `published` can be written

### Requirement: Every declared search filter is a real property of its schema (REQ-003)
Every entry in a `search.filters` list MUST be the name of a property that exists in that schema's `properties` block; `McpAnnotationValidator::validateFilters()` rejects an unknown filter with `mcp-unknown-filter-property` and fails the register import. The declared filters MUST be: `publication` → `title`, `organization`, `status`, `publicatiedatum`, `retentionCategory`; `catalog` → `title`, `organization`, `status`, `listed`, `slug`; `document` → `title`, `organization`, `mimeType`, `publicatiedatum`; `organization` → `name`, `oin`, `tooi`, `rsin`; `theme` → `title`, `sort`.

#### Scenario: The register imports without a dialect validation error
- GIVEN the five curated schemas declare their `search.filters`
- WHEN the OpenCatalogi register is imported into OpenRegister
- THEN `McpAnnotationValidator::validate()` returns no `mcp-unknown-filter-property` error
- AND no `mcp-unknown-verb`, `mcp-bad-scope`, `mcp-bad-hint`, or `mcp-missing-enabled` error is returned

### Requirement: Catalog-scoped search survives as a curated service tool (REQ-004)
`PublicationService` MUST expose a `searchCatalogPublications()` method annotated `#[McpTool(scope: 'read', readOnlyHint: true, destructiveHint: false, idempotentHint: true)]`, and the `catalog` argument MUST be required. A catalog in OpenCatalogi is a set of registers, schemas and filters (`catalog.registers`, `catalog.schemas`, `catalog.filters`) resolved by `getCatalogFilters()`, not a property on `publication` — so a derived `opencatalogi.publication.search` cannot express "search inside catalog X", and this capability MUST NOT be lost when the provider is deleted. The `catalog` argument is required specifically so the tool cannot degrade into a duplicate of the derived `opencatalogi.publication.search`. The method MUST delegate to the existing `searchPublications()` path so OpenRegister RBAC (`_rbac: true`) continues to filter every result row.

#### Scenario: An agent searches within one catalog
- GIVEN a catalog that spans two registers
- WHEN the agent calls `opencatalogi.searchCatalogPublications` with that catalog and a query
- THEN results are returned from both registers in that catalog's scope
- AND every returned row passed OpenRegister's RBAC read check

#### Scenario: The tool cannot be called without a catalog
- GIVEN an agent calls `opencatalogi.searchCatalogPublications` with a query but no catalog
- WHEN the tool validates its arguments
- THEN the call is rejected with a structured invalid-arguments error
- AND the agent is directed to `opencatalogi.publication.search` for an unscoped search

### Requirement: The publication file-attachment list survives as a curated service tool (REQ-005)
`PublicationService` MUST expose a `listPublicationFiles()` method annotated `#[McpTool(scope: 'read', readOnlyHint: true, destructiveHint: false, idempotentHint: true)]` that returns the **Nextcloud file** attachments of a publication. These files live in the Nextcloud file layer (`FileService::getFiles(object: $id, sharedFilesOnly: true)`), which no OpenRegister schema models, so no derived tool can reach them. The method MUST delegate to the existing `attachments()` path so both the catalog-scope gate (`isObjectInCatalogScope()`) and the RBAC read check on the parent object continue to run before any file list is returned. Its description MUST explicitly contrast it with the `document` schema tools, which return the object-modelled WOO *bijlagen*, so an LLM can tell the two apart.

#### Scenario: File attachments are returned for a publication the caller may read
- GIVEN a publication in this instance's catalog scope with two shared files
- WHEN the agent calls `opencatalogi.listPublicationFiles` with that publication's id
- THEN the two files are returned
- AND the catalog-scope gate and the RBAC read check on the publication both ran first

#### Scenario: An out-of-scope publication yields no files
- GIVEN a publication that is not in any catalog configured on this instance
- WHEN the agent calls `opencatalogi.listPublicationFiles` with its id
- THEN no file list is returned
- AND the caller cannot use the tool to enumerate files outside this installation's namespace

### Requirement: No hand-written MCP tool provider remains in OpenCatalogi (REQ-006)
OpenCatalogi MUST NOT ship any `IMcpToolProvider` implementation. `lib/Mcp/OpenCatalogiToolProvider.php`, `tests/Unit/Mcp/OpenCatalogiToolProviderTest.php`, and the `OCA\OpenRegister\Mcp\IMcpToolProvider::opencatalogi` alias in `lib/AppInfo/Application.php` MUST be deleted, because a hand-written tool takes precedence over a derived tool and a surviving `opencatalogi.getPublication` would permanently shadow `opencatalogi.publication.get` and render the dialect inert. OpenCatalogi MUST instead register `lib/Mcp/OpenCatalogiScannableServices.php` implementing `IMcpScannableServices` under the alias `OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi`, returning `[PublicationService::class]`, so the two surviving `#[McpTool]` methods are discovered.

#### Scenario: The derived tools are not shadowed
- GIVEN the OpenCatalogi register declares the dialect
- WHEN the MCP tool catalogue for app id `opencatalogi` is enumerated
- THEN `opencatalogi.publication.search` and `opencatalogi.publication.get` are present
- AND `opencatalogi.searchCatalog` and `opencatalogi.getPublication` are absent

#### Scenario: The curated service tools are discovered
- GIVEN `OpenCatalogiScannableServices` returns `[PublicationService::class]`
- WHEN OpenRegister's `AttributeToolScanner` scans that class
- THEN `opencatalogi.searchCatalogPublications` and `opencatalogi.listPublicationFiles` are registered
- AND each declares `scope: "read"` and `readOnlyHint: true` so Hermiq classifies them as read tools rather than failing open
