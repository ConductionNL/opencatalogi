# Tasks

## 1. The migration

- [x] 1.1 `opencatalogi:documents:attach-to-publications`, dry-run by default.
- [x] 1.2 Resolve the publication link in all three shapes real data holds: a
      bare uuid, an object with `id`, and an object with only `slug`.
- [x] 1.3 Carry `summary` and `description` onto the file's description, joined
      rather than one overwriting the other.
- [x] 1.4 Carry the title as a label.
- [x] 1.5 Carry `publicationDate` / `depublicationDate` onto the file's window.
- [x] 1.6 `--keep-documents`, so a first pass on real data can be inspected
      before anything is removed.

## 2. Refusals

- [x] 2.1 A document with no files is left in place.
- [x] 2.2 A document whose publication cannot be found is left in place.
- [x] 2.3 An unparseable date becomes null, never the migration's runtime.

## 3. Tests

- [x] 3.1 Pin the link shapes, the metadata mapping and the date handling.

## 4. Follow-on, not in this change

- [ ] 4.1 Repoint the ~8 UI sites that read the `document` collection.
- [ ] 4.2 Rewrite the content-search e2e to assert the publication surfaces
      from its own attached file, with no document object involved.
- [ ] 4.3 Revert the schema widening in `PublicationQueryService` once nothing
      needs it, and remove its tests.
- [ ] 4.4 Remove `document` from the register descriptor and run
      `openregister:schemas:prune-retired --app opencatalogi --slug document`.
