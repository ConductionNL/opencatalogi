# Agent tools (MCP)

An AI assistant that runs on OpenRegister's MCP host can read OpenCatalogi. It sees
only what the person it acts for may already read, and it can change nothing.

OpenCatalogi writes no tool code of its own for this. The tools come from two places:

- **Schema tools.** Three schemas declare the `x-openregister-mcp` block, in
  `lib/Settings/register.d/mcp-tool-surface.json`. OpenRegister derives a `search` and
  a `get` tool from each one.
- **Curated tools.** Two methods on `PublicationService` carry the `#[McpTool]`
  attribute. `OpenCatalogiScannableServices` tells OpenRegister to scan that class.

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

## Why there is no write tool

Whether a publication is public is decided by a date field: the public may read a
publication once `publicationDate` has passed, and a catalog the same way through
`published`. A tool that could update a publication could therefore publish it by
writing one date. A published Woo document is harvested, sitemapped and listed in the
federation within the hour, and withdrawing it later does not call those copies back.

So no schema declares `create`, `update` or `delete`, and both curated tools declare
`readOnlyHint: true` and `scope: read`. A drafting assistant needs a way to declare
"this write may not touch the publication dates" first; the dialect does not have one yet.

## Tool ids that changed

The hand-written provider is gone. `opencatalogi.getPublication` is now
`opencatalogi.publication.get` (its file list is `opencatalogi.listPublicationFiles`),
and `opencatalogi.searchCatalog` is now `opencatalogi.searchCatalogPublications`,
which requires a catalog.
