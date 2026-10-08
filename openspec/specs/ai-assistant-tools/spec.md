---
status: done
---

# ai-assistant-tools Specification

## Purpose
Let an AI assistant that runs on OpenRegister's MCP host search the catalogues and look up a publication, with the same read rights the person asking already has. OpenCatalogi offers two read-only tools through `lib/Mcp/OpenCatalogiToolProvider.php`, registered under the alias `OCA\OpenRegister\Mcp\IMcpToolProvider::opencatalogi` in `lib/AppInfo/Application.php:199-206`. Every read goes through `PublicationService`, which reads through OpenRegister with RBAC on, so the tools add no access path of their own.

This spec describes the code as it is on development (7 October 2026). The open change `opencatalogi-mcp-adoption` replaces the hand-written provider with OpenRegister's declarative MCP surface; when it lands, this spec moves with it.

Capability row: `int-mcp`.

## Requirements

### Requirement: The app offers two read-only assistant tools (AIT-001)
The system SHALL expose exactly two tools to the MCP host: `opencatalogi.searchCatalog` (subject `catalog`, action `search`, required argument `query`, optional `limit` and `catalog`) and `opencatalogi.getPublication` (subject `publication`, action `get`, required argument `id`), as declared in `OpenCatalogiToolProvider::TOOL_DESCRIPTORS` (`lib/Mcp/OpenCatalogiToolProvider.php:87-135`). The system MUST NOT offer a tool that creates, changes, publishes or deletes anything.

#### Scenario: The host lists the tools
<!-- @e2e exclude Server-side MCP tool registry with no OpenCatalogi page; covered by PHPUnit on OpenCatalogiToolProvider. -->
- WHEN OpenRegister's MCP host asks the provider registered as `IMcpToolProvider::opencatalogi` for its tools
- THEN it receives the two descriptors `opencatalogi.searchCatalog` and `opencatalogi.getPublication`
- AND neither descriptor names a write action

#### Scenario: An unknown tool id is refused with the list of tools
<!-- @e2e exclude Server-side MCP dispatch; no UI surface. -->
- WHEN the host invokes a tool id the provider does not declare
- THEN the provider returns an error envelope with code `unknown_tool` and a message that names the available tool ids (`OpenCatalogiToolProvider.php:184-199`)

### Requirement: Search returns only publications the caller may read (AIT-002)
`opencatalogi.searchCatalog` SHALL run a full-text search through `PublicationService::index()` with `_search` set to the trimmed query, `_limit` and `_page: 1`, scoped to one catalogue when `catalog` is given (`OpenCatalogiToolProvider.php:217-254`). Results MUST be filtered by OpenRegister RBAC for the calling user. The tool SHALL return at most 20 publications, a `sources` entry per publication with type `opencatalogi.publication`, its uuid, a deep link `/apps/opencatalogi/publications/<uuid>` and a label, and `resultsTruncated` with `resultsTotalCount` when more matched (`:270-297`, `:458-480`).

#### Scenario: A search scoped to one catalogue
<!-- @e2e exclude Server-side MCP tool; covered by PHPUnit on OpenCatalogiToolProvider. -->
- WHEN the assistant calls `opencatalogi.searchCatalog` with `query` "afvalbeleid" and `catalog` set to a catalogue slug
- THEN the provider searches that catalogue only
- AND every publication in the result is one the caller can read in OpenRegister
- AND each has a source with a deep link into the app

#### Scenario: An empty query or a limit out of range is refused
<!-- @e2e exclude Server-side argument validation; covered by PHPUnit. -->
- WHEN the assistant calls the search with an empty `query`, or with a `limit` below 1 or above 50
- THEN the provider returns an error envelope with code `invalid_arguments` and runs no search

#### Scenario: A failing search does not throw
<!-- @e2e exclude Server-side failure handling; covered by PHPUnit. -->
- WHEN the publication search throws
- THEN the provider logs the failure with the caller's user id and returns an error envelope with code `internal_error`

### Requirement: Looking up a publication honours the read check (AIT-003)
`opencatalogi.getPublication` SHALL read one publication by id, uuid or slug through `PublicationService::show()`, which runs OpenRegister's read check before anything is returned (`OpenCatalogiToolProvider.php:315-370`). When the check refuses or the publication does not exist, the tool MUST return an error envelope (`forbidden` or `not_found`) and no publication data. On success it SHALL return the publication, up to 20 attachments and one source entry. A failure to list the attachments MUST NOT fail the lookup; the attachment list is then empty (`:384-410`).

#### Scenario: A publication the caller may read
<!-- @e2e exclude Server-side MCP tool; covered by PHPUnit. -->
- WHEN the assistant calls `opencatalogi.getPublication` with the uuid of a publication the caller can read
- THEN the provider returns `success: true`, the publication, its attachments and a source with the deep link

#### Scenario: A publication the caller may not read
<!-- @e2e exclude Server-side RBAC verdict; covered by PHPUnit. -->
- WHEN the read check refuses the caller
- THEN the provider returns an error envelope with code `forbidden` and the message "You are not allowed to read this publication, or it does not exist."
- AND no field of the publication is in the response

### Requirement: The provider never throws to the host (AIT-004)
Every call to `invokeTool()` SHALL return an array: a success payload or `{"error": {"code", "message"}}` (`OpenCatalogiToolProvider.php:58`, `:422-424`). The provider MUST NOT let an exception from a service reach the MCP host.

#### Scenario: A service error becomes an envelope
<!-- @e2e exclude Server-side error contract; covered by PHPUnit. -->
- WHEN any service call inside a tool throws
- THEN the host receives an error envelope and the assistant can tell the user the lookup failed
