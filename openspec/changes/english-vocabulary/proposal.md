# English vocabulary for opencatalogi

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **0 Dutch-named schemas and 1 Dutch property**: `besluit`.

This is the smallest change in the fleet and exists mainly so opencatalogi is
not silently skipped — and because `besluit` is a **fleet-shared word** that
appears in procest (`Besluit`, `besluitinformatieobject`), openconnector
(`besluitStatus`) and openregister (`besluitdatum`).

## What changes

| Dutch | English |
|---|---|
| `besluit` | `decision` |

The word must be the same across all four apps. `decision` is the natural
English term and matches `besluitStatus` → `decisionStatus` and `besluitdatum`
→ `decisionDate` already proposed elsewhere.

⚠️ In ZGW, `Besluit` is a **formal legal instrument**, not merely "a decision".
If procest's `Besluit` schema needs the statutory marker (§4), opencatalogi's
plain `besluit` property may or may not refer to the same thing — confirm before
assuming they are one concept.

## Tasks

- [ ] Confirm whether `besluit` here means the ZGW `Besluit` instrument or a
      generic decision.
- [ ] Rename to `decision` (or `formalDecision` if it is the ZGW instrument).
- [ ] Check lib/ + src/ for Dutch in class, method and file names — the scan
      only covered schema properties.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- A one-property change is easy to under-review; the cross-app word choice is
  the only real decision here, and getting it wrong desynchronises four apps.
