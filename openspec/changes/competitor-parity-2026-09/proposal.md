---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

The opencatalogi half of the competitor parity programme. Source of
record: the round 4 discovery sweep,
`procest/_round4/discovery/` in ConductionNL/market-intelligence
(`build-plan.md`, `candidates.json`, `decisions.md`,
`found-and-lacking.md`, written 2026-09-14).

Ruben's ownership rule: dossiq reaches 100% comparability with the
competition, and logic that belongs to another app is specified in that
app. dossiq consumes it. opencatalogi is one of those owner apps, and
this umbrella indexes what opencatalogi owes.

The sweep read 36 systems into 1,117 findings, 631 consolidated
candidates and 70 capability clusters. **Three clusters and 27
candidates land on opencatalogi.** Each cluster is one change, and all
three are opened in this PR. Nothing here is implemented: every change
carries its own `proposal.md`, `design.md`, `specs/` and `tasks.md`.

## The changes

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `publication-inspection-and-the-national-indexes` | 50, publication, inspection and the national indexes | 15 | L | none | declare publication on the case type and the decision type, hand the decision and its documents over, read the publication state back |
| `the-public-and-community-surface` | 17, the public and community surface | 7 | M | D5 | publish, and do not host a public surface of its own |
| `published-service-and-case-type-catalogue` | 31, published service catalogue and case type catalogue | 5 | M | none | consume through `StoreController`, and import case types from the published catalogue |

## Why these three, in this order

1. `the-public-and-community-surface` is what the other two publish onto.
   The plan makes cluster 50 depend on it.
2. `publication-inspection-and-the-national-indexes` carries eleven
   `must` candidates, the most of any opencatalogi cluster, and the
   statutory ones: Woo actieve openbaarmaking, terinzagelegging,
   bekendmaking and the landelijke Woo-index.
3. `published-service-and-case-type-catalogue` stands alone. It has no
   dependency in the plan.

## The decisions that govern them

- **D6, the promotion bar.** Ruben answered relevance-led: every `must`
  candidate enters, whatever the passer count. Eleven of cluster 50's
  fifteen are `must`.
- **D21, the 143 candidates with no driven passer.** Ruben answered that
  documented-only candidates are admitted and labelled. Six of cluster
  50's nine passers are documented, so most of that cluster rests on
  vendor claims, and every requirement says which.
- **D17, the `not` bucket.** The product serves a broad market including
  MKB, so a `not` rating does not disqualify a candidate.
- **D5, which revivals to re-argue**, is named on cluster 17 by the plan.
  None of that cluster's seven candidates is one of the five revivals
  D5 weighs, so nothing in the change turns on the answer.

## Halves that sit in other repos

- **The portal surface is portaliq's.** `C-intake-15`, the public request
  catalogue, is opencatalogi's to publish. Rendering it as the citizen's
  entry point and starting the form behind an entry is portaliq's
  `portal-intake-form-as-an-object`.
- **The gateway is integriq's.** The plan's mechanism line for cluster 50
  says so: opencatalogi holds the Woo and DiWoo publication, integriq
  holds the gateway to LVBB, the Woo index and the other national
  endpoints.
- **The decision and its documents are dossiq's.** opencatalogi publishes
  what it is handed. It does not decide what a besluit contains.

## Build order

- [ ] 1 `the-public-and-community-surface`
- [ ] 2 After `the-public-and-community-surface`:
  `publication-inspection-and-the-national-indexes`
- [ ] 3 `published-service-and-case-type-catalogue`, no dependency
- [ ] 4 Hand the dossiq halves of the three clusters to the dossiq lane,
  with the candidate ids.
- [ ] 5 Hand the gateway half of cluster 50 to integriq, with the
  candidate ids `C-decisions-11` and `C-decisions-21`.
- [ ] 6 Add any opencatalogi change opened from the sweep after this
  umbrella to the index, in the same PR.
