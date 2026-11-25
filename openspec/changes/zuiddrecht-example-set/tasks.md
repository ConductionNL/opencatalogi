# Tasks: zuiddrecht-example-set

Kind: data. No PHP changes. Depends on openregister#4603 (the loader; on OpenRegister `development` since 2026-10-13, not yet on `beta`).

## 1. The fragment

- [x] 1.1 `lib/Settings/example-sets/zuiddrecht.json` with 26 objects and no `after`: it needs only the cast.
- [x] 1.2 The catalog `zuiddrecht` over register `publication`, schema `publication`, with `showWithheld` and `hasWooSitemap`.
- [x] 1.3 8 themes that follow the subjects of the Zuiddrecht site.
- [x] 1.4 13 publications with categories from `tooi_waardelijsten.json`, relative dates and links to their themes.
  - 25 Nov 2026: the categories are removed again by the 17:55 decision (the demo publications are not Woo publications).
- [x] 1.5 2 `withheldDocument` rows on the Woo decision.
- [x] 1.6 Sanne's dossier and saved search, owner `{{cast:sanne.uid}}`.

## 2. The documents

- [x] 2.1 `scripts/example-sets/make-zuiddrecht-documents.py` writes 20 one-page PDFs, plain Python, every page marked as demo content.
- [x] 2.2 The PDFs are committed in `lib/Settings/example-sets/zuiddrecht/<publication key>/`. Each opens without repair in a PDF reader.

## 3. Checks

- [x] 3.1 Static check: every placeholder resolves and every object validates against the merged register schema, with no undeclared key. Run once by hand when the fragment was written; no test or script repeats it, so it does not catch drift when the register schemas change. Done when a unit test or script performs it.
- [ ] 3.2 On an instance whose OpenRegister carries the loader (`development` since 2026-10-13; not yet `beta`): `occ openregister:example-set:install zuiddrecht --check` exits 0, then the install exits 0.
  - Live 25 Nov 2026 (b-opencatalogi, Nextcloud 34 on PostgreSQL, OpenRegister main df7349fe, opencatalogi development 40899b430): `--check` exits 0 (every object passes its schema: 18 publications, 8 themes, 2 withheld documents and the rest). The install exits 2: 17 Woo publications are refused by `DiwooCompletenessListener` with "the DiWoo metadata is incomplete: publisher" (REQ-DWP-003), so the two `withheldDocument` rows and Sanne's dossier cannot resolve their references either. Cause, upstream: the publisher's TOOI identifier is read from the organisation on the object's `@self.organisation`, the loader writes every object under the installing admin's active organisation (COORDINATOR-QUEUE 13:28Z, option (a) there), and a cast organisation (`openregisterOrganisations`) takes no TOOI key. A made-up identifier is no way out: the DiWoo XSD refuses an unknown organisation (`DiwooXsdValidatorTest`, gm9999). Also seen: `knowledgeArticle.sourceCase` and `serviceStatus.stateSetBy` are written but not kept. Stays unticked until the loader writes a set's objects under its cast organisation and that organisation can carry a TOOI identifier.
  - 25 Nov 2026, later (b-opencatalogi): the 17:55 decision makes the Zuiddrecht demo publications non-Woo (no `wooCategory`, no TOOI), so the publisher check no longer applies. `wooCategory` is removed from the 17 publications, and the five retention-review publications get a description, so the set also passes the required-to-publish rule of publication-required-at-publish (#3122). Live on Nextcloud 34.0.6 + PostgreSQL 16 with OpenRegister main 292aa09d, after `example-set:remove`: `--check` exits 0, and the install writes every object (18 publications, 8 themes, 2 withheld documents, the collection, the saved search, 3 case types, 5 services, 2 knowledge articles, 4 sources, 5 statuses; nothing "Not written"). It still exits 2, only because the loader's read-back reports `knowledgeArticle.sourceCase` and `serviceStatus.stateSetBy` as "written but not kept". Both are stored (read from their magic tables: `2026-0412`, `admin`). They carry a property read rule with `reveal: explicit`, which the read-back does not reveal. That is OpenRegister's to fix, logged for the OR lanes. Stays unticked until the install exits 0.

## 4. Loader features used (openregister#4603)

- [x] 4.1 Files on an object: every publication lists its PDFs in `files` with `share: true` (REQ-EXS-013).
- [x] 4.2 An organisation reference: catalog and publications carry `{{organisation:zuiddrecht}}` (REQ-EXS-016).
