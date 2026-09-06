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
