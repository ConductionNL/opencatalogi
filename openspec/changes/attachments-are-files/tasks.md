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

## 4. Retirement

- [x] 4.1 Repoint the UI. Measured: there was nothing to repoint. Nothing in
      `lib/` or `src/` CREATES a document, no view lists them, and
      `PublicationDetail.vue` already reads attachments as files on the
      publication (`/objects/{reg}/{schema}/{publicationId}/files/...`). The
      schema was already vestigial: legacy rows, the search path that reaches
      them, and the e2e fixture that seeded them.
- [x] 4.2 Rewrite the content-search e2e: it seeds a file on the publication
      and asserts the PUBLICATION surfaces. The catalog no longer needs to list
      the document schema, which is the clearest statement of what changed.
- [ ] 4.3 NOT DONE, deliberately. Reverting the widening would break content
      search on any instance that has not yet run the migration, and those
      instances are exactly the ones with documents to find. It self-disables:
      `documentSchemaIdsOfRegister()` keeps only schemas slugged `document`, so
      it returns `[]` and widens nothing once the schema is gone. Remove it in
      a later release, after the fleet has migrated.
- [x] 4.4 Remove `document` from the register descriptor, bumping BOTH
      `info.version` and the register's own version, or the import is
      version-gated and never applies.
- [x] 4.5 Drop `document` from `SettingsService`'s three object-type lists and
      from `WOO536RepairReadRules`, which no longer backfills read rules onto a
      schema that is going away.

## 5. The operator sequence, and its one dead end

Retiring the schema on an existing instance is two commands, and the second
refuses if the first was not run:

1. `occ opencatalogi:documents:attach-to-publications --apply`
2. `occ openregister:schemas:prune-retired --app opencatalogi --slug document --apply`

Verified live: with documents still present, step 2 reports
`SKIP — still owns objects. Re-run with --force to drop them, or migrate them
first.` The descriptor change alone removes nothing, because `ImportHandler`
UNIONS schema ids — measured again here, the register still listed schema 22
after the retired descriptor was force-imported.

⚠️ A document with NO FILES is the dead end: the migration refuses it (there is
nothing to move and its metadata would be lost) and the prune refuses it (the
schema still owns objects). The migration now says so in its output. It is a
per-document decision for the operator: copy what it says onto the publication
and delete it, or accept the loss with `--force`.

## 6. Found while retiring, not fixed here

- [ ] 6.1 `UnpublishedAttachmentsWidget` renders permanently empty. It calls
      `objectStore.fetchCollection('attachment')` and filters on
      `status === 'Concept'`, but there is no `attachment` schema in the
      descriptor, no `attachment` entry in `SettingsService`'s object types, and
      therefore no `attachment_register` / `attachment_schema` to resolve.
      Confirmed against a live instance: `GET /api/settings` returns no
      `attachment_*` key at all.

      It is a silent no-op, which is why nobody noticed: an empty widget looks
      exactly like a widget with nothing to show.

      Worth fixing properly rather than deleting, because the question it asks
      is now answerable and was not before. "Unpublished attachments" is
      `isPublished === false` on a publication's files, which openregister's
      `file-publication-window` change made a real property. That is a different
      data source (files, not objects), so it needs its own change.

## 7. Amendment 2026-10-05: a withdrawn attachment holds (row 4.16)

Build after `openregister/file-publication-window` has merged on OpenRegister `development`. Groups 1 to 6 above are unchanged and keep their own state. Read `openspec/woo-build-rules.md` first; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`, and check `FileService::unpublishFile()` and the window setter of `file-publication-window` in openregister before mocking them.

- [ ] 7.1 `withdrawFile()` sets the file's window end and stores the reason and editor on the file (REQ-ATT-103). Verify: `tests/Unit/Controller/PublicationStateControllerTest.php::testWithdrawFileSetsTheFilesDepublicationAndReason` (fails today).
- [ ] 7.2 `DcatMappingService` leaves an ended file out (REQ-ATT-103). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testAWithdrawnFileIsNotADistribution`.
- [ ] 7.3 `publishObjectAttachments()` and every other sharing path skip an ended or depublished file; list the paths with `git grep -n "createShareLink\|publishFile" lib` in the PR body (REQ-ATT-104). Verify: `tests/Unit/Service/EventServiceTest.php::testAutoPublishSkipsAWithdrawnFile` (fails today) and one test per other path found.
- [ ] 7.4 Add `POST /api/publications/{id}/files/{fileId}/republish` with the reason and update-right check (REQ-ATT-104). Verify: `PublicationStateControllerTest::testRepublishFileNeedsAReasonAndReopensTheWindow` and a route-table test.
- [ ] 7.5 Show withdrawn files as withdrawn with date and reason, and a Republish action, on the publication page (REQ-ATT-103, REQ-ATT-104). Verify: `tests/e2e/publish-and-withdraw.spec.ts` gains "the editor sees it", carrying `@e2e` REQ-ATT-103.
- [ ] 7.6 Live: withdraw an annex on the dev instance with auto-publish on, save the publication, and paste the annex's share state and the sitemap page in the PR body (REQ-ATT-104). Verify: the pasted output.
- [ ] 7.7 Verification: `TMPDIR` a sibling directory outside the clone; PHPUnit by the `Tests:` line or `--no-coverage`; `run-hydra-gates.sh --base origin/development` counting the gates that ran; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`; one PR with `--base development`, merge development in, never rebase, no `Co-Authored-By`.

Done when merged on `development` with CI green. Row 4.16 becomes `production` only once a store release ships it.
