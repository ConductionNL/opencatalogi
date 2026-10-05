---
kind: code
depends_on: [publication-lifecycle-on-or, openregister/object-archive-state]
---

# Proposal: publication-withdrawal-aftercare

## Why

What happens after a publication changes or comes down is the part a reader and a court see. Today a correction overwrites the public record, so the version a reader cited is gone from the public side. A withdrawn record answers 404, the same as a link that never existed. And a withdrawn record can still be edited, files included, so the record of what was taken down can drift after the fact.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **5.4** "A corrected record keeps its earlier public version reachable". Ours: partial, production. Evidence: "openregister keeps every version of the object and opencatalogi exposes the version history tab, so the earlier content is retrievable by an officer. Nothing serves the earlier version at a public URL".
- **5.5** "A depublished record leaves a tombstone, not a dead link". Ours: partial, build. Evidence: "opencatalogi lib/Settings/register.d/publication-inspection-and-the-national-indexes.json#depublication records reason, withdrawals and a file, which is the material for a tombstone; whether the public URL serves one needs the instance".
- **5.18** "A withdrawn record is frozen: edits to it and to its documents are refused, and the product says why". Ours: partial, build. Evidence: "openregister lib/Controller/ObjectStateController.php::freeze marks an object frozen, and SaveObject and RevertHandler then refuse edits with ObjectStateWriteException::frozen, which names who froze it, when and in which state. opencatalogi's DepublicationService never freezes a withdrawn publication (no freeze call anywhere in opencatalogi), and the file endpoints do not check the frozen marker, so documents stay editable".

Read on development at 35999c296. The evidence names an opencatalogi `FilesController`; there is none. Documents are written through OpenRegister's file endpoints and, inside opencatalogi, through `EventService::publishObjectAttachments()`, `PublicationStateController::withdrawFile()` and the attachment modals. OpenRegister's `ArchiveHandler::freeze(string $identifier, ?string $reason, ?string $state, ?string $register, ?string $schema): array` refuses with `ArchiveNotOfferedException` unless the schema declares `x-openregister-archive` with `enabled: true`; the publication schema does not.

## What changes

- When a public publication is corrected, OpenCatalogi first stores what the public saw as a `publicationVersion`: the public fields, the version number, the moment it was superseded, and a copy of each public document. The public API lists a publication's earlier versions and serves each at its own stable URL, marked superseded, for as long as the publication itself is public.
- A withdrawn or archived publication that was once public answers 410 with a tombstone: its id, the withdrawal date and a public reason. The tombstone is a page about the withdrawal, not the record. It carries the title only when the officer chose that at withdrawal. Everything else stays invisible, so RET-001 and RET-006 hold.
- The depublication records a `publicReason`, separate from the internal `reason`, and a `tombstoneShowsTitle` choice, default no.
- Withdrawing a publication freezes it in OpenRegister with the withdrawal reason. OpenCatalogi's own document writes check the frozen marker and refuse with OpenRegister's message, which names who froze it, when and why. OpenRegister's file endpoints refuse the same, through the OpenRegister amendment of `object-archive-state`. The publication page shows the frozen marker and offers no edit or upload.

## Fail closed

- An earlier version is served only while the publication itself is publicly readable. When that check fails or cannot be made, the version URL answers 404.
- A version snapshot carries only fields the public read of the publication returned at that moment. It never carries a field the public could not see.
- A tombstone is served only for a publication with a `depublication` record or a stored `firstReleasedAt`. A draft that was never public still answers 404, so the tombstone does not reveal that a draft exists.
- A tombstone carries no title unless the officer chose to show it, and never a summary, an organisation's internal note or a document.
- When the freeze fails, the withdrawal still stands, because down is the safe side. The answer names the failure and the page shows the publication as not frozen, so nobody believes it is.

## Out of scope

- The portal page of the tombstone and of an earlier version. portaliq renders both from these endpoints.
- File writes on OpenRegister's own endpoints honouring the frozen marker: that is the OpenRegister amendment of `object-archive-state` (extending REQ-OAS-004).
- Versions of a publication that was never public. Only public versions are kept.

## Dependencies

- `openregister/object-archive-state` (OpenRegister, open change, 14 of 19 tasks on development at 1dc6a46; amended in this programme, wave 1, so file writes honour the frozen marker). The freeze and its message exist today; the file half is that amendment. This change needs `ArchiveHandler::freeze()` and `ObjectStateWriteException::frozen()` as they are, and needs the amendment for the scenario on OpenRegister's file endpoint.
- `publication-lifecycle-on-or` (opencatalogi, planned, wave 1): `firstReleasedAt`, read by the tombstone guard.
- `integration-publish-by-reference` is not needed: a reference is a URL, and the snapshot records it as it was.

## Wave

Wave 2. It needs the stored release stamp of `publication-lifecycle-on-or` and the file half of `openregister/object-archive-state`, both wave 1.

## Decisions

- D5, row 5.5: "Row wins with the tombstone scoped as a page about the withdrawal, not the record." Implemented as written. RET-006 says an archived publication is invisible on every public surface; it still is. The tombstone is a new response about the withdrawal at the old URL, not the record. RET-001 is unchanged.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 5.4 | A corrected record keeps its earlier public version reachable | partial | REQ-PWA-001, scenario "The cited version is still there" |
| 5.5 | A depublished record leaves a tombstone, not a dead link | partial | REQ-PWA-002, scenario "A withdrawn link answers with a tombstone" |
| 5.18 | A withdrawn record is frozen: edits to it and to its documents are refused, and the product says why | partial | REQ-PWA-003, scenarios "An edit to a withdrawn publication is refused with the reason" and "A document write is refused" |
