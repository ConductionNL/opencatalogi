# Agent tools (MCP)

An AI assistant that runs on OpenRegister's MCP host can read OpenCatalogi, and it can do
four governed writes. It sees only what the person it acts for may already read, writes only
what that person may write, and never makes a publication public, takes one down or archives
one without a person deciding it.

OpenCatalogi writes no tool code of its own for this. The tools come from two places:

- **Schema tools.** Three schemas declare the `x-openregister-mcp` block, in
  `lib/Settings/register.d/mcp-tool-surface.json`. OpenRegister derives a `search` and
  a `get` tool from each one.
- **Curated tools.** Two read methods on `PublicationService` and four write methods on
  `PublicationAgentTools` carry the `#[McpTool]` attribute. `OpenCatalogiScannableServices`
  tells OpenRegister to scan those classes.

## The tools

| Tool | What it answers |
| --- | --- |
| `opencatalogi.publication.search` / `.get` | Publications; search filters `title`, `organization`, `status`, `publicationDate`, `retentionCategory` |
| `opencatalogi.catalog.search` / `.get` | Catalogs; search filters `title`, `organization`, `status`, `listed`, `slug` |
| `opencatalogi.theme.search` / `.get` | Themes, to turn a theme id on a publication into its name; filters `title`, `sort` |
| `opencatalogi.searchCatalogPublications` | A full-text search inside one catalog, across every register and schema that catalog spans. The catalog is required; an unscoped search goes through `publication.search`. At most 50 results. |
| `opencatalogi.listPublicationFiles` | The Nextcloud files stored on one publication. A publication outside this instance's catalogs, or one the caller may not read, answers `not_found`. |

Every read runs through OpenRegister with RBAC on. The curated tools delegate to the same
`index()` and `attachments()` paths the public API uses, so the catalog-scope check and
the read check run as they do there. A failure answers a fixed message, never server detail.

Other schemas (`listing`, `glossary`, `usageCounter`, and the OOAPI education schemas)
declare nothing, so no tool exists for them. Organisations are OpenRegister's shared
organisation objects, not a schema of this app.

## The write tools

| Tool | Scope | Reach | Human step | What it writes |
| --- | --- | --- | --- | --- |
| `opencatalogi.createDraftPublication` | create | instance | none: a draft is not public | `title`, `summary`, `description`, `organization`, `themes` |
| `opencatalogi.publishPublication` | update | external | a reviewer or publisher moved the publication to `approved` | the publish transition, as the publish button runs it |
| `opencatalogi.depublishPublication` | update | external | a publisher completes the task the tool creates | nothing itself; the completion withdraws |
| `opencatalogi.archivePublication` | update | instance | a publisher completes the task the tool creates | nothing itself; the completion archives |

- **Drafts.** `createDraftPublication` takes only the five descriptive fields. A publication or
  withdrawal date, a status or a retention field rejects the whole call. The draft gets the
  lifecycle's initial `draft` state and is invisible to the public.
- **Publishing.** `publishPublication` publishes only publications in the state `approved`. The
  agent cannot approve, so a draft or a publication in review is refused with `not_approved`,
  also where review is switched off. Every other check is the publish button's: the publish
  right, the update right on the item, an embargo (never lifted by an agent) and a publication
  without documents. Each id is answered as published or refused with its reason.
- **Withdrawing and archiving.** `depublishPublication` and `archivePublication` write nothing
  about the publication. Each publication gets an OpenRegister task for the group
  `opencatalogi-publishers`, on the publication, with the reason the agent's user gave. When a
  publisher completes the task, OpenCatalogi takes the action in that publisher's session and
  with that publisher's rights: a withdrawal runs the withdraw button's path (the public API
  drops it, every channel it reached is told, the depublication record names the reason and the
  publisher), an archive runs the lifecycle's `archive` transition. A rejected or cancelled task
  changes nothing.
- **Irreversible distribution.** Publish and depublish have reach `external`. A published
  publication is harvested, sitemapped and listed in the federation within the hour, and a
  withdrawal does not call those copies back. Both descriptions say so.
- **Other schemas.** No write tool exists for catalogs, documents, organisations, themes or any
  other schema, and no tool declares scope `delete`.
- **Audit.** Every call of the four tools, refusals included, is recorded on OpenRegister's
  hash-chained audit trail as `mcp.<tool>`, with the person the agent acts for, the tool id, a
  digest of the arguments and a summary of the answer. The trail does not name the agent itself
  yet; OpenRegister's record has no field for it.

Every guarantee above holds in OpenCatalogi's own code, whatever MCP client calls the tools: the
argument allow-lists, the RBAC path and the human step do not rely on Hermiq's agent grants.

## Tool ids that changed

The hand-written provider is gone. `opencatalogi.getPublication` is now
`opencatalogi.publication.get` (its file list is `opencatalogi.listPublicationFiles`),
and `opencatalogi.searchCatalog` is now `opencatalogi.searchCatalogPublications`,
which requires a catalog.
