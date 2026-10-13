# Tasks: zuiddrecht-example-set

Kind: data. No PHP changes. Depends on openregister#4603 (the loader).

## 1. The fragment

- [x] 1.1 `lib/Settings/example-sets/zuiddrecht.json` with 26 objects and no `after`: it needs only the cast.
- [x] 1.2 The catalog `zuiddrecht` over register `publication`, schema `publication`, with `showWithheld` and `hasWooSitemap`.
- [x] 1.3 8 themes that follow the subjects of the Zuiddrecht site.
- [x] 1.4 13 publications with categories from `tooi_waardelijsten.json`, relative dates and links to their themes.
- [x] 1.5 2 `withheldDocument` rows on the Woo decision.
- [x] 1.6 Sanne's dossier and saved search, owner `{{cast:sanne.uid}}`.

## 2. The documents

- [x] 2.1 `scripts/example-sets/make-zuiddrecht-documents.py` writes 20 one-page PDFs, plain Python, every page marked as demo content.
- [x] 2.2 The PDFs are committed in `lib/Settings/example-sets/zuiddrecht/<publication key>/`. Each opens without repair in a PDF reader.

## 3. Checks

- [x] 3.1 Static check: every placeholder resolves and every object validates against the merged register schema, with no undeclared key.
- [ ] 3.2 After openregister#4603 merges: `occ openregister:example-set:install zuiddrecht --check` exits 0, then the install exits 0.

## 4. Loader features used (openregister#4603)

- [x] 4.1 Files on an object: every publication lists its PDFs in `files` with `share: true` (REQ-EXS-013).
- [x] 4.2 An organisation reference: catalog and publications carry `{{organisation:zuiddrecht}}` (REQ-EXS-016).
