# Proposal: opencatalogi-mcp-adoption

## Summary

Adopt ADR-063 (hydra #102) in OpenCatalogi: replace the hand-written `OpenCatalogiToolProvider` with OpenRegister's declarative MCP surface. A curated set of **5 of OpenCatalogi's 14 schemas** (`publication`, `catalog`, `document`, `organization`, `theme`) declares the `x-openregister-mcp` dialect, from which OpenRegister derives `opencatalogi.{schema}.{verb}` tools. The surface is **read-only**: no `create`, `update` or `delete` verb is declared, because in OpenCatalogi **a write verb is a publish verb** — `publicatiedatum` and `published` are ordinary writable properties that appear *inside the RBAC match rule* that grants the `public` group read access. Two capabilities in the provider are genuinely non-CRUD and survive as `#[McpTool]` methods on `PublicationService`; the provider class itself is deleted.

## Motivation

- **ADR-063 forbids hand-written MCP tool code.** OpenCatalogi ships `lib/Mcp/OpenCatalogiToolProvider.php` with 2 tools (`opencatalogi.searchCatalog`, `opencatalogi.getPublication`), registered as an `IMcpToolProvider::opencatalogi` alias in `lib/AppInfo/Application.php:166`.
- **Hand-written tools shadow derived ones.** `opencatalogi.getPublication` is a plain `publication` read; leaving it in place while declaring the dialect makes the dialect inert.
- **Publications are public-facing by design, which cuts both ways.** Read exposure is largely the point (WOO/DIWOO transparency; `publication.authorization.read` already grants the `public` group a conditional read). But that same design makes an agent-writable publication uniquely dangerous — see Risks.
- **Not everything in the provider is CRUD.** Catalog-scoped search and the file-attachment list are real capabilities the derived surface cannot supply. A naive "delete the provider, declare the dialect" migration would silently drop both.

## Affected Projects

- [ ] Project: opencatalogi — declare `x-openregister-mcp` on 5 schemas; add 2 `#[McpTool]` methods to `PublicationService`; add `OpenCatalogiScannableServices`; delete `OpenCatalogiToolProvider` + its test + its DI alias
- [ ] Project: openregister — **no code change**; consumed read-only as the derivation registry (`SchemaDerivedToolProvider`, `AttributeToolScanner`, `McpAnnotationValidator`)

## Capabilities

- `mcp-tool-surface` — new capability: OpenCatalogi's declarative, curated, read-only agent tool surface plus its two curated non-CRUD tools

## Scope

### In Scope

- `x-openregister-mcp` under `configuration` on 5 schemas: `publication`, `catalog`, `document`, `organization`, `theme`
- Read verbs only (`search`, `get`), each with `scope: read` and `readOnlyHint: true`
- `search.filters` lists cross-checked against each schema's real properties
- Two `#[McpTool]`-annotated methods on `lib/Service/PublicationService.php`:
  - `searchCatalogPublications()` — catalog-scoped, cross-register/cross-schema search (not derivable)
  - `listPublicationFiles()` — the Nextcloud **file** attachments of a publication (not derivable; no schema models NC files)
- `lib/Mcp/OpenCatalogiScannableServices.php` implementing `IMcpScannableServices`, registered under `OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi`
- Deletion of `lib/Mcp/OpenCatalogiToolProvider.php`, `tests/Unit/Mcp/OpenCatalogiToolProviderTest.php`, and the `IMcpToolProvider::opencatalogi` alias

### Out of Scope

- Any `create` / `update` / `delete` verb on any OpenCatalogi schema — **refused**, see Risk 1
- The 9 schemas left OFF (`page`, `menu`, `glossary`, `listing`, `usageCounter`, and the 4 OOAPI schemas)
- Changes to the WOO/DCAT publishing pipeline, the harvester, or federation
- Hermiq-side prompt/agent changes

## Approach

1. Add `configuration["x-openregister-mcp"]` to the 5 curated schemas in `lib/Settings/publication_register.json` (each already has a `configuration` object holding `autoPublish`, so the key is added alongside it).
2. Add the two curated, LLM-shaped methods to `PublicationService`, annotated `#[McpTool(..., scope: 'read', readOnlyHint: true)]`, delegating to the existing `searchPublications()` / `attachments()` code paths so RBAC and catalog-scope enforcement are unchanged.
3. Add the `IMcpScannableServices` opt-in listing `PublicationService`, then delete the provider, its test and its alias.

## New Dependencies

None. OpenRegister is already a hard dependency.

## Impact

- **Tool ids change.** `opencatalogi.getPublication` → `opencatalogi.publication.get`; `opencatalogi.searchCatalog` → `opencatalogi.searchCatalogPublications` (kept, but now requires a `catalog` argument — see design Decision 4).
- **Tool count grows** from 2 to 12 (5 schemas × 2 read verbs + 2 curated tools).
- `lib/Settings/publication_register.json` is re-imported. No property, `authorization` block or `autoPublish` value changes — this change adds keys, it does not alter the existing publication posture.

## Cross-Project Dependencies

- **openregister** ≥ the commit carrying `SchemaDerivedToolProvider`, `AttributeToolScanner` and `IMcpScannableServices` (present at `origin/development`).
- **hermiq** classifies write/destructive tools from the 3-segment verb suffix, and (since hermiq #57) honours declared hints on 2-segment curated tools. Both curated tools here declare `readOnlyHint: true` + `scope: 'read'`, so they classify correctly and are not a governance hole.

## Risks

### Risk 1: An agent-writable publication is an agent that can publish

- **Severity**: High
- **Detail**: `publication.authorization.read` is `[{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}, "authenticated"]`, and `catalog` uses the same shape on its `published` property. Publication state is therefore **a data field inside the RBAC match rule**, not a separate privileged act. An agent granted `opencatalogi.publication.update` needs no special permission to publish: it only has to write a past date into `publicatiedatum`. Once published, a WOO document is picked up by the DCAT harvester, the sitemap and federated listings — depublication does not un-distribute it. `configuration.autoPublish` is `false` on every schema, which does **not** mitigate this: `autoPublish` governs the automatic path, and a direct write to `publicatiedatum` bypasses the question entirely.
- **Mitigation**: **No write verb is declared on any schema.** The MCP surface is read-only. Drafting assistance for publications is a legitimate future feature but needs a dialect that can constrain which properties a write may touch (`publicatiedatum` / `published` must be un-writable by an agent); recorded as a DEFERRED_QUESTION.

### Risk 2: Deleting the provider silently drops two real capabilities

- **Severity**: Medium
- **Detail**: `searchCatalog` scopes a search to a catalog, and a catalog in OpenCatalogi is *a set of registers + schemas + filters* (`catalog.registers`, `catalog.schemas`, `catalog.filters`) resolved by `getCatalogFilters()` — **not** a property on `publication`. A derived `opencatalogi.publication.search` therefore cannot express "search within catalog X". Likewise `getPublication`'s attachment list comes from `FileService::getFiles(sharedFilesOnly: true)` — the Nextcloud file layer, which no schema models.
- **Mitigation**: Both are reclassified as genuine non-CRUD and preserved as `#[McpTool]` methods on `PublicationService` (ADR-063 rule 4: business logic lives in a service, not a provider).

### Risk 3: Unpublished publications are readable by authenticated users

- **Severity**: Medium
- **Detail**: `publication.authorization.read` grants `authenticated` an *unconditional* read — the `publicatiedatum <= $now` match applies only to the `public` group. So an authenticated caller (and therefore an agent acting as that caller) can read embargoed / not-yet-published publications via `opencatalogi.publication.search`.
- **Mitigation**: This is the app's **existing** posture — the same is true through the UI and the REST API today, and the MCP surface inherits rather than widens it. Not silently accepted: flagged here and recorded as a DEFERRED_QUESTION for the OpenCatalogi product owner, since "any employee can read an embargoed WOO document" is a defensible design for a records system and an indefensible one for an agent surface.

### Risk 4: Two competing "attachment" surfaces confuse the LLM

- **Severity**: Low
- **Detail**: `document` (the object-modelled WOO *bijlage*, with its own `publicatiedatum` gate) and `listPublicationFiles` (NC file attachments) are both, loosely, "the attachments of a publication". An LLM given two similar tools picks wrongly.
- **Mitigation**: Distinct names and honest, explicitly-contrasting descriptions: the `document` tools say "the published document objects (bijlagen) of a publication"; `listPublicationFiles` says "the Nextcloud file attachments stored on a publication object". Both are real and both are used, so neither is dropped.

### Risk 5: Hermiq prompts referencing the old tool ids

- **Severity**: Low
- **Mitigation**: Grep hermiq for `opencatalogi.searchCatalog` / `opencatalogi.getPublication` before merge.

## Rollback Strategy

Revert the commit. Re-importing the register drops the `x-openregister-mcp` keys (no other schema key is touched, so nothing else moves); restoring `OpenCatalogiToolProvider.php` + the DI alias restores the 2 hand-written tools. No data migration, no destructive schema change.

## Open Questions

- Should an authenticated non-admin agent be able to read a publication whose `publicatiedatum` is in the future (Risk 3)? If not, the fix is an `authorization` change to `publication`, which is a product decision beyond this change's scope.
- Can the dialect constrain *which properties* a write verb may touch? Without that, no `create`/`update` on `publication` or `catalog` can be safe.
- Do any installs model bijlagen **only** as NC files rather than `document` objects (or vice versa)? If one pattern is dead in practice, the corresponding tool should be dropped.
