# Tasks: publications-name-their-responsible-organisation

Read `openspec/woo-build-rules.md` first. Start once `diwoo-metadata-on-the-publication` is merged. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Read the `nc-organisation` projection's real keys in openregister (`OrganisationObjectSourceProvider`: `tooi`, `rsin`, `kvk`) and the real signature of OpenRegister's file metadata write behind `files#updateMetadata` before mocking. Use a valid test RSIN that passes the 11-proef (for example 002220647) and an invalid one (123456789); assert the check in a test rather than trusting the example.

## 1. Responsible organisation

- [ ] 1.1 Add `responsibleOrganization` to `#publication` in a fragment with `slug` and a bumped version, with labels (REQ-PRO-001). Verify: `tests/Unit/Settings/ResponsibleOrganisationSchemaTest.php::testTheReferencePointsAtNcOrganisation`.
- [ ] 1.2 Emit `diwoo:verantwoordelijke` from it in `SitemapService::mapDiwooDocument()`, report a missing TOOI identifier, and feed `DiwooCompletenessListener` when the XSD fixture marks it mandatory (REQ-PRO-001). Verify: `tests/Unit/Service/SitemapServiceTest.php::testVerantwoordelijkeComesFromTheResponsibleOrganisation` (fails today), `::testWithoutAResponsibleOrganisationThePublisherIsResponsible`, `::testAResponsibleOrganisationWithoutTooiIsOmittedAndReported`; `tests/Unit/Listener/DiwooCompletenessListenerTest.php::testAMandatoryVerantwoordelijkeWithoutTooiIsRefused` when applicable.
- [ ] 1.3 Add Responsible organisation to the publication form and show both on the page (REQ-PRO-001). Verify: `tests/e2e/responsible-organisation.spec.ts` "an editor sets the responsible organisation", carrying `@e2e` REQ-PRO-001.

## 2. RSIN travels

- [ ] 2.1 Add `PublisherIdentity::of()` with the 11-proef (REQ-PRO-002). Verify: `tests/Unit/Service/Publication/PublisherIdentityTest.php::testAValidRsinIsResolved`, `::testAnInvalidRsinIsNotStamped`, `::testAMissingOrganisationGivesNoRsin`.
- [ ] 2.2 Write the file metadata on attach from `BatchPublicationWriter::attach()` and the upload path, store `publisherRsin`, and, when `EmbeddedTitleWriter` exists, write the RSIN into the embedded metadata (REQ-PRO-002). Verify: `PublisherIdentityTest::testTheRsinIsWrittenToTheFileMetadataOnAttach` (fails today) driven through `BatchPublicationWriter::attach()`, so the call has its caller; and `tests/Unit/Service/Publication/EmbeddedTitleWriterTest.php::testTheRsinIsEmbeddedBesideTheTitle` when that class exists.
- [ ] 2.3 Live: attach a document on the dev instance and paste the file's metadata read back from OpenRegister in the PR body (REQ-PRO-002). Verify: the pasted read-back.

## 3. Docs

- [ ] 3.1 Document the two organisations and the RSIN for editors in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 4. Verification

- [ ] 4.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 4.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 4.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 4.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 2.21 and 2.22 become `production` only once a store release ships it.
