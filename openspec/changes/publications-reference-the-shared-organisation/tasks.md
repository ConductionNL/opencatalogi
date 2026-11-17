# Tasks

## 1. Point at the shared organisation

- [x] 1.1 `publication.organization` and `catalog.organization` `$ref`
      `nc-organisation`.
- [x] 1.2 Remove the `organization` schema from the register descriptor.
- [x] 1.3 Remove the SECOND copy from the `ooapi-catalog-publication` fragment.
      Two descriptors shipped the same slug.
- [x] 1.4 Bump both `info.version` and the register's version, or the
      version-gated import never applies.

## 2. Configuration

- [x] 2.1 Drop `organization` from the object types resolved out of this app's
      own import result. The schema is not ours to resolve.
- [x] 2.2 Resolve `organization_source` / `_register` / `_schema` from
      OpenRegister's directory register instead.
- [x] 2.3 Fail soft when OpenRegister or the projection is absent: leave the
      keys unset rather than failing an import.

- [x] 2.4 Drop the stale seeds the schema left behind: `default-org` and the four
      `"organization": "default-org"` values in `publication_register.json`
      (0.7.0 -> 0.7.1), and the three `organization` demo seeds in
      `opencatalogi_mock_register.json` (1.0.0 -> 1.0.1). With stackiq installed
      the slug resolved to stackiq's schema and the seed failed NOT NULL on its
      required `type` (Rotterdam stack, register 23 x schema 109).

## 3. Frontend

- [x] 3.1 Nothing. `getCollection('organization')` resolves through the three
      config keys and nothing else, so repointing them moves the picker onto the
      shared organisation with no Vue change. Verified by reading the store's
      resolution, not assumed.

## 4. Retirement on an existing instance

Same two-command shape as `document`, and the second refuses if the first was
not run:

1. `occ openregister:organisations:adopt --register publication` — adopts the
   leaf rows into OpenRegister's Organisation, preserving each uuid so stored
   references keep resolving, and recording a merge where the same legal entity
   already exists.
2. `occ openregister:schemas:prune-retired --app opencatalogi --slug organization --apply`

- [ ] 4.1 Run on the fleet. The descriptor change alone removes nothing: (live pass, decision 139)
      `ImportHandler` unions schema ids.

## 6. Amendment 2026-10-05: rights follow the named unit (row 12.34)

Build only after `openregister/object-organisation-from-a-property` has merged on OpenRegister `development`; before that the annotation is dropped on import. Groups 1 to 4 above (and the items numbered 5.x inside group 4) keep their own state. Read `openspec/woo-build-rules.md` first; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`.

- [ ] 6.1 Declare `x-openregister-organisation: {fromProperty: "organization"}` on `#publication` and bump the schema and register versions (REQ-SHO-102). Verify: `tests/Unit/Settings/PublicationOrganisationAnnotationTest.php::testTheFromPropertyAnnotationSurvivesTheImport` (fails today) and `::testANonMemberIsRefused`.
- [ ] 6.2 Limit the organisation picker on the publication form to the user's organisations (all for an administrator) (REQ-SHO-102). Verify: `tests/e2e/shared-organisation.spec.ts` "naming the unit is what scopes the rights", carrying `@e2e` REQ-SHO-102, with two seeded organisations and two users.
- [ ] 6.3 Add the reconcile step to the upgrade notes in `docs/`, and run it on the dev instance as a dry run; paste the listed count in the PR body (REQ-SHO-102). Verify: the pasted dry-run output; a grep for U+2014 on the doc.
- [ ] 6.4 Verification: `TMPDIR` a sibling directory outside the clone; PHPUnit by the `Tests:` line or `--no-coverage`; `run-hydra-gates.sh --base origin/development` counting the gates that ran; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`; one PR with `--base development`, merge development in, never rebase, no `Co-Authored-By`.

Done when merged on `development` with CI green. Row 12.34 becomes `production` only once store releases ship this and the OpenRegister change.
