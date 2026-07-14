# Design: opencatalogi-mcp-adoption

## Context

OpenCatalogi ships `lib/Mcp/OpenCatalogiToolProvider.php` — an `IMcpToolProvider` with 2 hard-coded read tools (`opencatalogi.searchCatalog`, `opencatalogi.getPublication`), registered in `lib/AppInfo/Application.php:166` under the alias `OCA\OpenRegister\Mcp\IMcpToolProvider::opencatalogi`, and unit-tested in `tests/Unit/Mcp/OpenCatalogiToolProviderTest.php`. Unlike Scholiq's provider, it is **not** specced in any canonical `openspec/specs/**` capability — a grep for `mcp` across `openspec/specs/` returns nothing. So this change adds a capability rather than removing one.

ADR-063 (hydra #102) replaces the hand-written pattern with a per-schema `x-openregister-mcp` block, validated by `McpAnnotationValidator` (`VERBS = search|get|create|update|delete`, `SCOPES = read|create|update|delete`, `HINT_KEYS = readOnlyHint|destructiveHint|idempotentHint`) and read by `SchemaDerivedToolProvider` from **`$schema->getConfiguration()['x-openregister-mcp']`** — i.e. `components.schemas.<schema>.configuration["x-openregister-mcp"]`, exactly as in the pipelinq exemplar. Every OpenCatalogi schema already has a `configuration` object (holding `autoPublish`), so the key slots in beside it.

The register holds 14 schemas: 10 in `lib/Settings/publication_register.json` and 4 OOAPI schemas in `lib/Settings/register.d/ooapi-catalog-publication.json`.

## Goals / Non-Goals

**Goals**
- A curated, read-only derived surface over the entities a citizen, journalist or civil servant actually asks about.
- Preserve the two capabilities in the provider that the derived surface genuinely cannot supply.
- Make it structurally impossible for an agent to publish a document.
- Zero hand-written MCP tool *code* — curated behaviour lives on a service, annotated.

**Non-Goals**
- Any write path. See Decision 3.
- Changing `authorization`, `autoPublish`, or any existing publication behaviour.
- Exposing the CMS chrome (`page`, `menu`) or federation internals (`listing`).

## Decisions

### Decision 1: Curate 5 of 14 schemas — ON list

| Schema (slug) | Verbs | `search.filters` (all verified real properties) | One-line justification |
|---|---|---|---|
| `publication` | search, get | `title`, `organization`, `status`, `publicatiedatum`, `retentionCategory` | The core WOO/DIWOO entity — "which besluiten did this municipality publish about X" is the whole point of the app; `publicRead` already applies via `{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}`. |
| `catalog` | search, get | `title`, `organization`, `status`, `listed`, `slug` | "Which catalogues exist, what is in them" — a catalog is the app's top-level navigational unit; its `authorization.read` already carries the same public-conditional shape on `published`. |
| `document` | search, get | `title`, `organization`, `mimeType`, `publicatiedatum` | The object-modelled WOO *bijlage* — "which documents are attached to this besluit"; carries its own `publicatiedatum` public-read gate, so an unpublished bijlage stays unpublished. |
| `organization` | search, get | `name`, `oin`, `tooi`, `rsin` | The publisher's identity (OIN/TOOI/RSIN). `publication.organization` is a reference, so without `organization.get` the agent can only ever report an opaque id. `authorization.read` is `["public"]`. |
| `theme` | search, get | `title`, `sort` | The thematic vocabulary. `publication.themes` is an array of references — same argument as `organization`: the agent needs `theme.get` to turn an id into a word. `authorization.read` is `["public"]`. |

Every filter was cross-checked against the schema's `properties` block in `lib/Settings/publication_register.json`. `McpAnnotationValidator::validateFilters()` rejects an unknown filter with `mcp-unknown-filter-property` and fails the register import, so an un-cross-checked list is a hard import failure.

10 derived tools + 2 curated = 12.

### Decision 2: What is left OFF — 9 schemas, and why

- **`page`, `menu`** — CMS chrome (nav items, static pages, `hideBeforeLogin` flags). Nobody asks an assistant about a menu item. OFF.
- **`listing`** — federation/directory internals: `statusCode`, `lastSync`, `integrationLevel`, a sync endpoint URL. Operator plumbing, and its content is an implementation detail of the directory protocol. OFF.
- **`usageCounter`** — its `authorization` is `{"read": ["admin"], ...}`. Declaring the dialect on it would derive tools that deny for every non-admin caller — a tool that mostly returns "forbidden" is worse than no tool, because the LLM still pays its token cost and still tries it. It is also an aggregate (view/download counts per day), which an agent should get from a report, not by paging a counter table. OFF.
- **`glossary`** — genuinely safe (`authorization.read: ["public"]`) and mildly useful ("what does *besluit* mean here"). Left OFF purely on tool-budget grounds: ADR-063 rule 3, bias to fewer. This is the first schema to promote if a real user question needs it.
- **The 4 OOAPI schemas** (`course`, `program`, `offering`, `organization` in `register.d/ooapi-catalog-publication.json`) — an education-catalogue publication profile, populated only on installs that publish OOAPI. The education domain belongs to **scholiq**, which is getting its own curated surface in the same wave; declaring `opencatalogi.course.search` alongside `scholiq.course.search` would give an agent two near-identical course tools over different data. OFF, deliberately, to avoid the cross-app collision.

### Decision 3: The surface is read-only — every write verb is refused

This is not a default preference; it follows from the data model.

```json
"publication": {
  "authorization": {
    "read": [ { "group": "public", "match": { "publicatiedatum": { "$lte": "$now" } } }, "authenticated" ]
  }
}
```

**`publicatiedatum` is an ordinary writable property that appears inside the RBAC match rule.** Publication is therefore not a privileged *act* an agent could be denied — it is a *value* an agent could write. An agent holding `opencatalogi.publication.update` publishes a document to the entire public internet by setting one date field to yesterday. `catalog` has the identical shape on its `published` property.

This is the "publish = RBAC, not self-published" trap in its purest form, and it is why `configuration.autoPublish: false` (which is set on every schema) provides **no** protection here: `autoPublish` governs whether the *app* publishes an object automatically; it says nothing about a caller writing the date directly.

The consequence of getting this wrong is not recoverable in the way a normal bad write is. A published WOO document is harvested (DCAT), sitemapped, and mirrored into federated listings within the hour. Setting `depublicatiedatum` afterwards removes it from *this* instance; it does not un-distribute it. There is no "undo" for a premature disclosure of a government record.

So: **no `create`, `update` or `delete` verb on any OpenCatalogi schema.** Not on `publication`, not on `catalog`, not on `document` (which carries the same `publicatiedatum` gate), and not on the harmless-looking `theme` / `organization` either — a write there is not dangerous but it is not *wanted*, and every declared write verb is a governance liability that has to be justified.

A publication-drafting assistant is a legitimate product idea. It needs a dialect that can say "this `create` may not set `publicatiedatum`" — a property-level write constraint the dialect does not have. DEFERRED_QUESTION.

### Decision 4: Provider surgery — one tool is CRUD, two capabilities are not

Read of `OpenCatalogiToolProvider` at HEAD, method body by method body:

| Hand-written tool | What the body actually does | Classification | Disposition |
|---|---|---|---|
| `opencatalogi.searchCatalog` | `PublicationService::index($catalogId, ['_search' => …, '_limit' => …])` → `searchPublications()` → `getCatalogFilters($catalogId)` resolves the catalog's **registers + schemas + filters** and searches across them | **Split.** With no `catalog` argument it is a plain publication full-text search → derivable (`opencatalogi.publication.search`). With a `catalog` argument it is a cross-register/cross-schema scoped search that **no schema filter can express** — `publication` has no `catalog` property; the catalog *is* a search context. | **MOVE** to `PublicationService::searchCatalogPublications()` with `#[McpTool]`, and make the `catalog` argument **required** so it cannot degrade into a duplicate of the derived `publication.search` |
| `opencatalogi.getPublication` | `PublicationService::show($id)` (→ `ObjectService::find(_rbac: true)`) **plus** `fetchAttachments($id)` → `PublicationService::attachments()` → `FileService::getFiles(object: $id, sharedFilesOnly: true)` | **Split.** The `show()` half is a plain `publication` get → derivable (`opencatalogi.publication.get`). The attachment half reads the **Nextcloud file layer**, which no OpenRegister schema models and no derived tool can reach. | **DELETE** the get half; **MOVE** the attachment half to `PublicationService::listPublicationFiles()` with `#[McpTool]` |

Net: the provider retains **zero** tools ⇒ **`OpenCatalogiToolProvider` is deleted**, along with its unit test and its `IMcpToolProvider::opencatalogi` alias. Two curated tools survive, on the service that already owns the logic (ADR-063 rule 4). `lib/Mcp/OpenCatalogiScannableServices.php` implements `IMcpScannableServices` and returns `[PublicationService::class]`, registered under `OCA\OpenRegister\Mcp\IMcpScannableServices::opencatalogi` — mirroring `PipelinqScannableServices`.

**Why `catalog` becomes required on `searchCatalogPublications`.** Today it is optional, and an omitted catalog makes the tool a plain publication search — which is precisely what the derived `opencatalogi.publication.search` will now do. Two tools that do the same thing is the tool-confusion failure ADR-063's curation rule exists to prevent. Requiring the argument gives each tool exactly one job: `publication.search` searches publications; `searchCatalogPublications` searches *inside a catalog*, across whatever registers and schemas that catalog spans.

### Decision 5: Both curated tools declare hints and scope — honestly

ADR-063 rule 2: a 2-segment curated tool with no hints previously failed *open* in Hermiq — never stripped by default-deny, never gated for approval (hermiq #57 fixed this, but only for tools that declare). Both surviving tools read the method body and annotate accordingly:

```php
#[McpTool(
    name: 'searchCatalogPublications',
    description: 'Search publications inside one catalog. A catalog spans a set of registers and schemas, so this searches across all of them — use it when the question names a catalog. For a plain publication search, use opencatalogi.publication.search.',
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    scope: 'read',
)]
```

Both are genuinely read-only: `searchPublications()` and `attachments()` perform no writes, and both run through OpenRegister RBAC (`_rbac: true`) plus, in `attachments()`, the catalog-scope gate `isObjectInCatalogScope()`. `readOnlyHint: true` / `scope: 'read'` is therefore an honest annotation, not a convenient one.

## Risks / Trade-offs

- [An agent publishes a government record by writing a date] → no write verb is declared anywhere (Decision 3). The dialect is opt-in per verb, so this is enforced by absence, not by a runtime check.
- [Deleting the provider drops catalog-scoped search and file attachments] → both are preserved as `#[McpTool]` service methods (Decision 4). Verification asserts both appear in the catalogue.
- [`document` and `listPublicationFiles` look like the same tool to an LLM] → distinct names, explicitly-contrasting descriptions. Accepted; both surfaces are real and in use.
- [Authenticated users can read unpublished publications] → pre-existing app posture, inherited not widened. Flagged as a DEFERRED_QUESTION rather than silently accepted.
- [Making `catalog` required is a behaviour change] → an agent that wants an unscoped search now uses the derived `publication.search`. Net capability is unchanged; only the routing is cleaner.

## Migration Plan

1. Add `configuration["x-openregister-mcp"]` to the 5 curated schemas; `python3 -m json.tool` after every edit; do not touch `authorization`, `autoPublish`, or any property.
2. Add the two `#[McpTool]` methods to `PublicationService` (thin, LLM-shaped, delegating to the existing `searchPublications()` / `attachments()` paths).
3. Add `OpenCatalogiScannableServices` + its DI alias; delete the provider, its test, and the `IMcpToolProvider::opencatalogi` alias — same commit.
4. Re-import the register; assert 12 tools, and assert `opencatalogi.searchCatalog` / `opencatalogi.getPublication` are gone (no shadow).

Rollback: revert the commit and re-import.

## Open Questions

- **DEFERRED_QUESTION (property-level write constraints):** can `x-openregister-mcp` express "this `create`/`update` may not write `publicatiedatum` / `published`"? Until it can, OpenCatalogi declares no write verb, and the publication-drafting assistant cannot be built. This looks like an OpenRegister dialect issue.
- **DEFERRED_QUESTION (embargoed publications):** `publication.authorization.read` grants `authenticated` an unconditional read, so an agent acting as any signed-in user can read a publication whose `publicatiedatum` is in the future. Is that acceptable for an agent surface? This is a product decision for the OpenCatalogi PO; changing it is out of scope here.
- **DEFERRED_QUESTION (bijlage modelling):** do real installs use `document` objects, NC file attachments, or both? If one is dead in practice, drop the corresponding tool and the Risk-4 confusion disappears.
- **DEFERRED_QUESTION (glossary):** promote `glossary` to the curated set if term-definition questions turn out to be common?
