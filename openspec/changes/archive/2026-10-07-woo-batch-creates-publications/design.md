# Design: woo-batch-creates-publications

## D1 · Order of work in `publishBatch()`

1. Status must be `ready_for_review` and the approval chain must have a completed approval (unchanged).
2. Collect the publishable assessments (unchanged): `openbaar` uses `documentReference`, `deels_openbaar` uses `anonymizedDocument`.
3. Resolve every reference to a Nextcloud file (`BatchDocumentResolver`). A numeric reference is a file id. A reference starting with `/` is an absolute Nextcloud path. Anything else is a path in the files of the batch's `createdBy` user. A reference that resolves to nothing, or to a folder, is collected. If any are collected, the publish throws with their names and nothing is written.
4. Create the publication through OpenRegister (register `publication`, schema `publication`): `title` (the batch's `title`, else "Woo-publicatie {caseReference}"), `summary`, `wooCategory` (the batch's, else `infocat014` as today), `publicationKind: actief`, `caseReference`, `publicationDate` now, `status: published`.
5. For each file: `FileService::addFile()` on the publication with its name and content stream, then `publishFile()`.
6. Write the batch as today, with `wooPublication` extended by `publication` (the uuid) and `publicationUrl`.

If step 5 fails part way, the publication exists with the files attached so far. The exception names the file, the batch stays `ready_for_review`, and publishing again reuses the publication recorded on the batch (`wooPublication.publication`) instead of making a second one.

## D2 · Schema

`wooBatch` gains optional `title` and `wooCategory` (the TOOI enum, same as the publication's). The fragment `fix-woo-capability-provisioning.json` is edited in place, its schema version raised.

## Risks

Large files. `addFile()` takes a stream resource, so a file is never read into memory whole.
