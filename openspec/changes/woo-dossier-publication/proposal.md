---
kind: code
depends_on: []
---

# Proposal: woo-dossier-publication

## Why

A published Woo decision and an actively disclosed set of documents both end up as an opencatalogi publication. Today the publication cannot say which kind it is, which Woo request case it came from, or which period its documents cover. The portal search block cannot narrow by information category, organisation and publication period in the words the journey uses (hydra `woo-citizen-journey`, contract C6, journey step J2.2).

## What changes

- The `publication` schema gains three optional properties: `publicationKind` (`woo-besluit` or `actief`), `caseReference` (the source case) and `period` (`{ from, to }`, the period the documents cover).
- The information category is the existing `wooCategory` (TOOI codes `infocat001` to `infocat017`, already facetable and read by the Woo sitemap). No second property is added.
- `GET /api/search` accepts the portal's filter names, `informatiecategorie[]`, `organisation[]`, `periodFrom` and `periodTo`, and translates them to `wooCategory`, `organization` and a `publicationDate` range. The saved-search job uses the same translation.

## Hydra requirements implemented

- `woo-citizen-journey`: Both publishing paths MUST create a public, searchable publication (the publication fields both paths write).

## Deviations from the contract, found in the code

- C6 names a new `informatiecategorie` property. The publication already has `wooCategory` with the TOOI information category codes, and the Woo sitemap and retention defaults read it. A second property would split one fact in two. `wooCategory` is the information category; dossiq writes `wooCategory: infocat014`.
- The publication's organisation property is `organization`, not `organisation`. The search filter keeps the contract's name and maps it.
- `periodFrom` and `periodTo` filter on `publicationDate`, the publication period of J2.2. `period` on the publication is descriptive.

## Out of scope

The search block's facet widgets (portaliq). dossiq's publish action (dossiq). The batch path (`woo-batch-creates-publications`).
