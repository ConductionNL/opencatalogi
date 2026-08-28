# Tasks: opencatalogi-mcp-adoption

## Implementation Tasks

### Task 1: Declare the MCP dialect on the 5 curated schemas (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-exactly-five-curated-schemas-declare-the-mcp-dialect-req-001`
- **files**: `lib/Settings/publication_register.json`
- **acceptance_criteria**:
  - GIVEN `publication`, `catalog`, `document`, `organization`, `theme` WHEN edited THEN each carries `configuration["x-openregister-mcp"]` (beside the existing `autoPublish` key) with `enabled: true` and a `tools` block holding only `search` and `get`, each with `scope: "read"` and `readOnlyHint: true`
  - GIVEN the same five schemas WHEN their `search.filters` are read THEN every entry is a real property of that schema (per REQ-003's list)
  - GIVEN the whole file WHEN grepped for `x-openregister-mcp` THEN exactly 5 occurrences are found, and no `create`/`update`/`delete` verb appears anywhere
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/publication_register.json` runs THEN it exits 0, and no `authorization`, `autoPublish` or property key is altered or dropped
- [ ] Implement
- [ ] Test

### Task 2: Add the two curated `#[McpTool]` methods to `PublicationService` (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004`
- **files**: `lib/Service/PublicationService.php`
- **acceptance_criteria**:
  - GIVEN `searchCatalogPublications(string $catalog, string $query, int $limit = 20)` WHEN added THEN it carries `#[McpTool(scope: 'read', readOnlyHint: true, destructiveHint: false, idempotentHint: true)]`, requires `$catalog`, and delegates to the existing `searchPublications()` path so `_rbac: true` still filters every row
  - GIVEN `listPublicationFiles(string $id)` WHEN added THEN it carries the same read-only attribute and delegates to the existing `attachments()` path so `isObjectInCatalogScope()` and the RBAC read check both still run
  - GIVEN the two descriptions WHEN read THEN `listPublicationFiles` explicitly contrasts itself with the `document` schema tools, and `searchCatalogPublications` directs unscoped searches to `opencatalogi.publication.search`
  - GIVEN the touched file WHEN scoped PHPCS runs THEN it is clean and the SPDX docblock tags are intact
- [ ] Implement
- [ ] Test

### Task 3: Add the scannable-services opt-in and delete the provider (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006`
- **files**: `lib/Mcp/OpenCatalogiScannableServices.php` (new), `lib/Mcp/OpenCatalogiToolProvider.php` (delete), `tests/Unit/Mcp/OpenCatalogiToolProviderTest.php` (delete), `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `OpenCatalogiScannableServices` WHEN added THEN it implements `IMcpScannableServices`, returns `[PublicationService::class]`, and is registered under the alias `OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi`
  - GIVEN `lib/AppInfo/Application.php` WHEN read THEN the `IMcpToolProvider::opencatalogi` alias and the `use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;` import are gone
  - GIVEN the repo WHEN `grep -rn "OpenCatalogiToolProvider" lib/ tests/` runs THEN it returns nothing
  - GIVEN the test suite WHEN run the CI way THEN there are zero new failures against a self-measured baseline (the deleted provider test is removed, not skipped)
- [ ] Implement
- [ ] Test

### Task 4: Verify the derived surface and record the migration (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-the-mcp-surface-is-read-only-no-write-verb-is-declared-req-002`
- **files**: `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN the register is re-imported WHEN `McpAnnotationValidator::validate()` runs THEN no `mcp-unknown-filter-property`, `mcp-unknown-verb`, `mcp-bad-scope`, `mcp-bad-hint` or `mcp-missing-enabled` error is returned
  - GIVEN the MCP catalogue for app id `opencatalogi` WHEN enumerated THEN it contains exactly 12 tools — `{publication,catalog,document,organization,theme}.{search,get}` plus `searchCatalogPublications` and `listPublicationFiles` — and `opencatalogi.searchCatalog` / `opencatalogi.getPublication` are ABSENT (no shadow)
  - GIVEN the same catalogue WHEN every tool is inspected THEN no tool can write `publicatiedatum` or `published`
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the ADR-063 migration and the breaking tool-id changes
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate opencatalogi-mcp-adoption --type change --strict` passes
- [ ] Manual testing against acceptance criteria (catalog-scoped search returns rows; no write tool exists)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for the two new `PublicationService` methods (`tests/Unit/`); zero new failures vs a self-measured baseline
- [ ] All tests pass (CI-way, in the container)
- Newman/Postman: N/A — no HTTP endpoint is added. The MCP surface is served by OpenRegister's `/api/mcp`.
- Browser tests (Playwright MCP): N/A — no UI change.

## Documentation (company-wide ADR-010)
- [ ] `docs/` records the curated MCP schema set, the read-only posture, and why no write verb is declared (publish = a writable date field)
- Screenshots: N/A — no user-facing UI is added or changed.

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings. Tool descriptions are agent-facing prose read by an LLM, not UI copy.
