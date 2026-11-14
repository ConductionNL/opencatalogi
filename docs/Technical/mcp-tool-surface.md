# MCP tool surface

OpenCatalogi gives AI agents (through OpenRegister's MCP server and the AI chat companion) a small, read-only set of tools. It writes no tool provider code of its own: OpenRegister derives the tools from a declaration on the schemas (ADR-063), and two curated service methods cover what a schema cannot express.

## Derived tools

These schemas in `lib/Settings/publication_register.json` carry `configuration["x-openregister-mcp"]`, each with only a `search` and a `get`:

| Schema | Tools | Search filters |
|---|---|---|
| `publication` | `opencatalogi.publication.search`, `opencatalogi.publication.get` | `title`, `organization`, `status`, `publicationDate`, `retentionCategory` |
| `catalog` | `opencatalogi.catalog.search`, `opencatalogi.catalog.get` | `title`, `organization`, `status`, `listed`, `slug` |
| `theme` | `opencatalogi.theme.search`, `opencatalogi.theme.get` | `title`, `sort` |

Every filter is a property of its schema; OpenRegister refuses an unknown one when it imports the register.

Left out on purpose: `listing` (directory plumbing), `glossary` (tool budget; the first to add if people ask for term definitions), `usageCounter` (admin-only, so every tool would deny), and every schema added by a `register.d` fragment, including the OOAPI education schemas (scholiq owns that domain).

## Curated tools

`OpenCatalogiScannableServices` lists `PublicationService`, whose two `#[McpTool]` methods OpenRegister discovers:

- `opencatalogi.searchCatalogPublications(catalog, query, limit)` searches inside one catalog, across every register and schema it spans. The catalog is required, so it never duplicates `opencatalogi.publication.search`.
- `opencatalogi.listPublicationFiles(id)` lists the Nextcloud files shared with a publication. It runs through `attachments()`, so the catalog-scope check and the read check on the publication happen first.

## Why there is no write tool

Publishing is not an action an agent can be refused: it is a value. A publication is public once its `publicationDate` is in the past (and a catalog once `published` is), and those are ordinary writable properties inside the read rule. An agent allowed to update a publication could publish a government record by setting one date, and a published record is harvested and mirrored within the hour, so it cannot be taken back. `autoPublish: false` does not help, because a direct write to the date does not go through it. So no schema declares `create`, `update` or `delete`.
