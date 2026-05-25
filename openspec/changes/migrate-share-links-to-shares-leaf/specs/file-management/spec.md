---
status: draft
---

# file-management Specification (delta)

This delta migrates OpenCatalogi's bespoke public share-link creation
(FIL-005/006/007) to the **OpenRegister shares leaf** per hydra ADR-022. Share
creation, lookup, and URL resolution for files attached to Attachment /
Publication objects are consumed from the leaf — NOT reimplemented against
`OCP\Share\IManager` in `lib/Service/FileService.php`. The shares leaf widget is
surfaced on the publication detail page via the app manifest (ADR-024 / ADR-036).

## MODIFIED Requirements

### Requirement: Create public share links (IShare type 3) for files with configurable permissions (FIL-005)
The system MUST create public share links for files attached to Attachment /
Publication objects by **consuming the OpenRegister shares leaf** (integration
registry, ADR-019) — NOT by calling `OCP\Share\IManager` directly from a bespoke
`FileService::createShareLink()` method (hydra ADR-022). The leaf owns share-type
and permission semantics; OpenCatalogi requests a public (type-3, read-only by
default) share through the leaf and the bespoke `createShareLink()` /
`createShare()` methods are removed.

#### Scenario: Auto-publish requests a public share via the leaf
- GIVEN `auto_publish_attachments` is enabled
- AND an attachment file is published for a catalogue object
- WHEN the auto-publishing path needs a public download link
- THEN the share is created by calling the OpenRegister shares leaf service
- AND NO call is made to a bespoke `FileService::createShareLink()` /
  `createShare()` against `OCP\Share\IManager`
- AND the file remains anonymously downloadable (WOO public-access requirement)

#### Scenario: Shares leaf absent
- GIVEN the OpenRegister shares leaf / integration is not available
- WHEN a share is requested
- THEN OpenCatalogi degrades gracefully with a "sharing integration required"
  signal rather than minting a parallel bespoke share

### Requirement: Find existing share links for a file path (FIL-006)
The system MUST discover existing share links for a file attached to an
Attachment / Publication object by **consuming the OpenRegister shares leaf's
lookup capability** — NOT via a bespoke `FileService::findShare()` that queries
`OCP\Share\IManager` directly (hydra ADR-022). Shares previously minted as
ordinary Nextcloud type-3 shares MUST remain discoverable through the leaf.

#### Scenario: Resolve an already-shared attachment
- GIVEN an attachment file already has a public share (whether minted by the
  leaf or by the legacy bespoke path)
- WHEN OpenCatalogi resolves the share for that file
- THEN the existing share is returned via the shares leaf
- AND no duplicate share is created

### Requirement: Return full share link URLs including protocol and domain (FIL-007)
The system MUST obtain the full public share URL for an attachment from the
**OpenRegister shares leaf** rather than hand-assembling
`{protocol}://{host}/index.php/s/{token}` in a bespoke `FileService::getShareLink()`
(hydra ADR-022). The leaf is the single source of truth for canonical share-link
resolution.

#### Scenario: Surface share URL on the publication detail page
- GIVEN a publication with attachments that have public shares
- WHEN the publication detail page renders the shares widget
- THEN the share URLs are resolved by the shares leaf and shown in the
  shares widget placed via the app manifest (ADR-024 / ADR-036)
- AND OpenCatalogi does NOT hand-assemble the share URL in PHP
