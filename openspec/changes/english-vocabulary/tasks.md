# Tasks — english-vocabulary (opencatalogi)

Scan: **3 schemas / 5 Dutch properties**, **3 Dutch method names** (all in
`lib/Service/TooiVocabularyService.php`). No Dutch class or file names.

## 1. Rename app-owned properties

- [ ] 1.1 `publication.publicatiedatum` → `publicationDate` and
      `publication.depublicatiedatum` → `depublicationDate`; both titles already read
      `Publication Date` / `Depublication Date`.
- [ ] 1.2 The same two properties on `document`, in the same commit — they are the same
      pair and splitting them leaves the register half-renamed.
- [ ] 1.3 `wooBatch.besluit` → **`decisionLetter`**, not `decision`. Its own description
      calls it the Woo decision *letter*; procest's `Besluit` is the legal instrument.
      The proposal drafted `decision` — design.md supersedes that.

## 2. Rename Dutch method names, preserve external vocabulary

- [ ] 2.1 Rename `resolveOrganisatie` → `resolveOrganisation`, and rename the two
      `soortHandeling` methods so the identifier is English while still naming the
      external DiWoo list.
- [ ] 2.2 Verify no TOOI kern identifier, DiWoo value-list key, label or URI changed.
      These are published government vocabulary; renaming them makes Woo publications
      non-conformant.

## 3. Update consumers

- [ ] 3.1 Diff every read of the five old property names across `lib/` and `src/`
      against the new schema. Reads use `??`, so a missed one renders empty rather than
      failing — the suite passes either way.
- [ ] 3.2 Check `x-openregister-*` expression strings for the old names; static analysis
      cannot see a property name inside a string.

## 4. Translations and verification

- [ ] 4.1 Add the Dutch words to `l10n/nl.json`, re-pointing existing keys rather than
      re-extracting; run `check-l10n`.
- [ ] 4.2 Re-run the token-aware scan; require 0 Dutch schemas and 0 Dutch properties.
- [ ] 4.3 Full test suite plus hydra gates 46 / 53 / 54 / 55 / 57 / 61.
- [ ] 4.4 Publish and depublish one item through the UI and confirm both dates still
      render — an empty date is what a missed `??` read produces, and it looks like
      absent data rather than a defect.

## Acceptance criteria

- Token-aware scan reports opencatalogi at 0/0.
- `wooBatch.decisionLetter` exists; no property named `decision` was introduced.
- Every TOOI/DiWoo identifier is byte-identical to before the change.
- Publication and depublication dates render correctly in the UI.
- Dutch UI labels unchanged; `check-l10n` passes.
