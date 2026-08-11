## Context

Token-aware scan of opencatalogi: **3 schemas / 5 Dutch properties**, and **3 Dutch
method names**, all three in one file (`lib/Service/TooiVocabularyService.php`). No Dutch
class names, no Dutch file names.

This is the second-smallest change in the fleet. Its value is not the five properties —
it is that opencatalogi holds one of the two `besluit` senses, and getting that word
wrong here desynchronises four apps.

## Goals / Non-Goals

**Goals:**

- Rename the five app-owned properties to English.
- Land `besluit` → `decisionLetter`, the ratified split, so the fleet does not collapse
  two concepts onto one word.
- Preserve the DiWoo/TOOI vocabulary identifiers, which are a published external
  standard rather than our naming.

**Non-Goals:**

- No schema renames — all three schema names are already English.
- No renaming of TOOI/DiWoo value-list URIs or member identifiers.

## Decisions

### 1. `besluit` → `decisionLetter`, NOT `decision` — the ratified split

The original per-app proposal wrote `decision`, and argued the word "must be the same
across all four apps". **That was wrong, and this design supersedes it.**

`wooBatch.besluit` describes itself as *"The Woo **decision letter** content or reference
for this batch"*. It is a **document**. procest's `Besluit` is the ZGW **legal
instrument**. Those are two concepts, and forcing one English word onto both recreates
exactly the failure that produced shillinq#485 — two vocabularies converging on one
schema name, silently merged into something no payload could satisfy.

| app | what it is | English |
|---|---|---|
| procest | ZGW `Besluit`, a legal instrument | `Decision` + statute marker |
| opencatalogi | the Woo decision **letter** for a batch | `decisionLetter` |
| openconnector | the RIS decision outcome (instrument sense) | `decisionStatus` |
| openregister | a permit decision date (instrument sense) | `decisionDate` |

**Decision:** `besluit` → `decisionLetter`. Three of the four apps share the instrument
sense; opencatalogi is the one that does not.

### 2. Woo is internationalised, because FOI is not uniquely Dutch

The Woo (Wet open overheid) implements freedom-of-information duties that exist as FOIA
in the US, Regulation 1049/2001 in the EU, and the Open Data Directive. It is not a
Dutch-only concept, so the ratified rule internationalises it rather than preserving it
with a statute marker.

`publicatiedatum` and `depublicatiedatum` already carry the English titles
`Publication Date` and `Depublication Date`. The rename copies the title.

### 3. TOOI / DiWoo identifiers are wire and stay

`TooiVocabularyService` resolves values against **the DiWoo `soortHandeling`
waardelijst** and returns **official TOOI kern identifiers**. These are published
government vocabulary URIs — the service's entire purpose is to produce identifiers that
match the standard.

**Decision:** keep the value-list keys, labels and URIs exactly as published. The three
Dutch *method* names (`resolveSoortHandeling`, `soortHandelingList`, `resolveOrganisatie`)
are ours and are renamed, but what they return is not touched. `resolveOrganisatie` →
`resolveOrganisation`; the two `soortHandeling` methods keep the standard's term in a way
that makes clear it names an external list — the method is renamed, the list identifier
is not.

## Risks / Trade-offs

- **`besluit` is renamed to `decision` by reflex, matching the other three apps** → two
  concepts collapse onto one word and the defect surfaces later as an unsatisfiable
  schema. Mitigated by this design overriding the proposal explicitly.
- **A TOOI identifier is internationalised** → published URIs stop resolving and Woo
  publications become non-conformant. Mitigated by scoping the rename to method names only.
- **`publicatiedatum` is read with `??` somewhere in `src/`** → the publication date
  silently renders empty. Mitigated by diffing read sites before landing.

## Migration Plan

1. Rename `publicatiedatum` → `publicationDate` and `depublicatiedatum` →
   `depublicationDate` on both `publication` and `document`.
2. Rename `wooBatch.besluit` → `decisionLetter`.
3. Rename the three method names; leave every TOOI/DiWoo identifier untouched.
4. Diff read sites, update `l10n/nl.json`, run the gates.

**Rollback:** all four steps are app-local and revert cleanly. No cross-app coordination
is required — `decisionLetter` is deliberately a word no other app uses.

## Open Questions

- None blocking. The `besluit` sense, which the fleet policy left open for this app, is
  resolved above from the property's own description.
