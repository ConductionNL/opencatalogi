# Tasks: subjects-as-first-class-records

Read `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; `ObjectService::findAll()` and `searchObjectsPaginated()` are the real methods to mock, and `ObjectEntity` getters are magic. Public search must stay inside `evaluateAsAnonymous()`: read the WOO-551 comment in `PublicationQueryService::assemblePublicSearchResults()` before touching it.

## 1. Schema

- [ ] 1.1 Add `featured`, `featuredOrder` and `slug` to `#theme`, bump its version, and add the Dutch and English labels (REQ-SUB-001). Verify: `tests/Unit/Settings/ThemeSchemaTest.php::testTheThemeSchemaDeclaresFeaturedOrderAndSlug` and `npm run check:schema-l10n`.
- [ ] 1.2 Add the featured-needs-title check to a pre-save listener registered in `Application::register()` (REQ-SUB-001). Verify: `tests/Unit/Listener/ThemeFeaturedCheckTest.php::testAFeaturedSubjectWithoutATitleIsRefused` on the REAL `ObjectCreatingEvent` and `ObjectUpdatingEvent`, and an `ApplicationRegisterInvariantTest` case.

## 2. Routes

- [ ] 2.1 `ThemesController::index()` honours `featured=true`, orders by `featuredOrder`, and adds `publicationCount` from the anonymous read (REQ-SUB-002). Verify: `tests/Unit/Controller/FeaturedThemesTest.php::testTheCountOnlyCountsPublicPublications` (fails today) and `::testFeaturedSubjectsComeInOrder`.
- [ ] 2.2 Add `themes#publications` at `GET /api/themes/{id}/publications` with its CORS preflight, and `publicationsUrl` on `show()` (REQ-SUB-003). Verify: `tests/Unit/Controller/ThemePublicationsTest.php::testADraftUnderASubjectIsNotListed` (fails today: no route), `::testTheSubjectListsItsPublicPublicationsNewestFirst`, `::testASlugResolves`, and `tests/Unit/AppInfo/RouteNameUniquenessTest.php`.

## 3. Search

- [ ] 3.1 Include themes in `assemblePublicSearchResults()` with `resultType` on every result and a `resultType` facet (REQ-SUB-004). Verify: `tests/Unit/Service/PublicSearchSubjectsTest.php::testASubjectIsAResultWithItsOwnType` (fails today), `::testAPublicationOnlySearchIsUnchanged`, and the existing `PublicationQueryServiceTest` and `PublicationQuerySearchContractTest` stay green.
- [ ] 3.2 Live: on the dev instance search for a word only a subject holds through `GET /apps/opencatalogi/api/search`, and paste the result in the PR body (REQ-SUB-004). Verify: the pasted result.

## 4. Officer UI

- [ ] 4.1 Add the featured toggle and order to the theme form, and the list of publications with their state to the theme page in `src/manifest.json` (REQ-SUB-001, REQ-SUB-003). Verify: `tests/e2e/subjects.spec.ts` "an administrator features a subject" and "a subject lists what is filed under it", carrying `@e2e` references; `npm run check:manifest`.

## 5. Cross-app contract

- [ ] 5.1 Write the response shapes of REQ-SUB-002 and REQ-SUB-004 into `docs/` (API for the portal) and link that page from the issue of `portaliq/home-and-theme-landing-pages` and `portaliq/search-filter-by-kind`, stating they must test against these keys. Verify: the links in the PR body; a grep for U+2014 on the doc returns nothing.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. Public search is central: run the full unit suite once.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-19 wants the `@e2e` references of 4.1.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 16.9 becomes `production` once a store release ships it; row 6.28 once this and `portaliq/home-and-theme-landing-pages` are both in store releases.
