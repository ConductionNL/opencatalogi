# An attachment is a file on the publication, not an object beside it

## Why

`document` was never a thing in its own right. Measuring the live schema, it is
a wrapper around one attached file, and every property it carries has a home
elsewhere:

| document property | where it lands |
| --- | --- |
| `filename`, `mimeType` | the file itself |
| `title` | a label on the file |
| `description`, `summary` | the file's description |
| `publication`, `organization` | the owning publication |
| `publicationDate` / `depublicationDate` | the file's publication window |

The last row is the only one that had no home, and it is why this could not be
done sooner: `publishFile()` was a boolean, so a bijlage could not be
depublished on a date independently of the publication it belongs to.
openregister's `file-publication-window` change closed that gap.

The second reason is the one that matters for search. OpenRegister already
resolves a file chunk to its OWNING object through
`FileMapper::findOwningObjectUuid()`, so once the file hangs from the
publication a body-text hit resolves to the publication directly. The schema
widening added for WOO-517 exists ONLY because the attachment is a separate
object sitting outside the catalog's schema scope. This change removes the
reason for that machinery rather than maintaining it.

It also ends a cross-app slug collision: `document` is claimed by both dossiq
and opencatalogi, and a schema slug is global per organisation, so
`SchemaMapper::find()` returns whichever row it reaches first.

## What changes

`opencatalogi:documents:attach-to-publications` moves each document's file onto
its publication, carrying the description, the title as a label, and the
publication window onto the file, then removes the document.

Dry-run by default, with `--keep-documents` for a first pass on real data so the
move can be inspected before anything is removed.

## What the migration refuses to do

A document with **no files** is left in place. It carries only metadata, so
migrating it would delete that metadata rather than move it, and the whole point
is that nothing is lost.

A document whose **publication cannot be found** is left in place. There is
nowhere to attach it, and attaching it somewhere else would be worse than
leaving it.

An **unparseable date becomes null**, never "now". A window starting at the
migration's own runtime would publish every attachment the moment the command
ran, which is the opposite of preserving what a publisher set.

## Sequencing

This change ships the migration only. Retiring the schema from the descriptor,
repointing the UI and reverting the WOO-517 widening follow once the migration
has been run on real data and inspected.

## Amendment 2026-10-05: Woo capability programme

Row 4.16, from `opencatalogi/_round1/compare/M1-rows.md`: "A single attachment is withdrawn without withdrawing its publication". Ours (`baseline/openwoo.tsv`): no. Evidence: "opencatalogi lib/Service/Publication/DepublicationService.php withdraws a publication; nothing withdraws one attachment". Wave 2, after `openregister/file-publication-window` (open, outside this plan, 10 of 12 tasks).

Re-checked on development at 35999c296: the evidence is out of date. `PublicationStateController::withdrawFile()` (route `publicationState#withdrawFile`, `POST /api/publications/{id}/files/{fileId}/withdraw`, main spec `publications` REQ-PPW-004, commit 45e70869a) already withdraws one document with a reason: it removes the file's public share through OpenRegister's `FileService::unpublishFile()` and stores a depublication naming the file. `PublicationVisibilityWidget.vue` calls it. So the row's core is built. Two gaps remain, and they decide whether the withdrawal holds:

- The withdrawal is not on the file. It removes the share, but the file's own publication window (OpenRegister REQ-FPW-101) keeps no end date. Nothing on the file says it was withdrawn, when, or why.
- It does not stay withdrawn. `EventService::publishObjectAttachments()` shares every file of a publication that has no share token when auto-publishing runs (`auto_publish_attachments`), so the next save of a public publication puts a withdrawn annex back online. The DCAT feed is not named in REQ-PPW-004 either.

What is added (REQ-ATT-103, REQ-ATT-104): withdrawing an attachment sets its depublication on the file's window with the reason; every path that publishes files skips a file whose window has ended; and the DCAT distribution goes with it. Fail closed: a file with an ended window is never re-shared by any automatic path; only an explicit republish of that file by an editor, with a reason, reopens it. No decision of D1 to D13 applies.

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 4.16 | A single attachment is withdrawn without withdrawing its publication | no (stale: REQ-PPW-004 is built) | REQ-ATT-103 and REQ-ATT-104, scenarios "A withdrawn annex stays withdrawn when the publication is saved" and "The withdrawal is on the file" |
