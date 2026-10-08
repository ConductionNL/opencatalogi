---
kind: code
depends_on: []
---

# Proposal: published-service-and-case-type-catalogue

Round 4 discovery sweep, cluster 31 "Published service catalogue and
case-type catalogue" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner opencatalogi, size
M, no decision named, no dependency. Candidates: `C-intake-15`,
`C-configuration-6`, `C-configuration-31`, `C-configuration-51`,
`C-configuration-73` (`candidates.json`, lane lines `intake.tsv:5`,
`configuration.tsv:97`, `cross-area.tsv:6`, `configuration.tsv:59`,
`configuration.tsv:125`).

## Summary

Two catalogues, pointing opposite ways. Outward, a public list of
everything a resident can request, each entry starting the right form.
Inward, case types taken from the national zaaktypecatalogus instead of
typed into a blank form, and kept in step with it. Both are catalogues,
so both live here.

## Why

`C-intake-15` is number 8 of the sweep's twenty-five loudest, a matrix
hole, and the lane's clause is short: this is the producten- en
dienstencatalogus a gemeente is obliged to publish. dossiq returns "zero
hits for a catalogue surface".

`C-configuration-51` is the other half of the same argument, from the
administrator's side. The lane wrote it plainly: i-Navigator is the
national zaaktypecatalogus, and starting a case type from the published
national definition rather than from a blank form is the difference
between two weeks and two hours.

The plan's mechanism line: "extend opencatalogi's publication of case
types; dossiq consumes through `StoreController`".

## The passers that prove it

Eight systems pass a member, six driven and two documented. Proving
system: glpi.

| candidate | relevance | driven | documented | evidence the lane cited |
|---|---|---|---|---|
| `C-intake-15` | must, hole | glpi, xxllnc-zaken, znuny | | Assistance, Service catalog (`src/Glpi/Controller/ServiceCatalog/IndexController.php`, `src/Glpi/Form/ServiceCatalog/`, `D-glpi-6`); a public index of case types (`D-xxllnc-97`) |
| `C-configuration-51` | must | dimpact-zac | atabix | Admin, inrichtingscheck (`browser-walkthrough-notes.md`, `D-dimpact-60`, `D-atabix-1`) |
| `C-configuration-73` | should | request-tracker | | Ticket, Extract Article (`MenuBuilder.pm`, `share/html/Articles/`, `D-request-tracker-10`) |
| `C-configuration-6` | could | xxllnc-zaken | | Catalogus, Details panel (`case-type-editor-anatomy.md`, `D-xxllnc-59`) |
| `C-configuration-31` | could | frappe-helpdesk | youtrack | `hd_article_feedback` (`D-frappe-helpdesk-9`, `D-youtrack-29`) |

dossiq rates four `no` and one `partial`. The partial is
`C-configuration-51`, and its note names the file:
`lib/Controller/ZtcController.php`.

## What opencatalogi builds

- **A public request catalogue.** Every service a resident or a company
  can request, grouped, searchable, each entry naming what it costs and
  how long it takes, and each entry carrying the form that starts it.
- **A case type published with its form and its API description**, so an
  administrator or an integrator jumps from the type to both.
- **Case types from a published external catalogue.** Import from the
  national zaaktypecatalogus, resynchronise, show the sync date, and say
  what changed at the source since.
- **Knowledge articles beside the catalogue**, with a reader's verdict
  counted and visible.
- **An answer turned into an article in one action**, so the answer to a
  common Woo question is written once.

## How dossiq consumes it

dossiq reads the catalogue through `StoreController`, which is how it
already reads from opencatalogi. It publishes its case types into the
catalogue and imports definitions from the national one. It does not host
a catalogue surface and does not gain a knowledge base.

## The portal half is portaliq's

`C-intake-15` has two halves. Publishing the catalogue is this change.
Rendering it as the citizen's entry point and starting the form behind an
entry is portaliq's `portal-intake-form-as-an-object`, opened in the same
wave. Neither repo builds both halves, and both say so.

## Existing specs it extends

`catalogs` (the catalogue and its slugs), `publications` (an entry is a
published record), `search` (the catalogue is searchable),
`dcat-ap-harvest` and the harvest changes (the external catalogue is a
feed we read), `content-management` (the article).

## ADRs

- ADR-031: catalogue entries and case-type definitions are declared
  objects.
- ADR-011: reuse OpenRegister's slug and date utilities.
- ADR-054: the catalogue is public, so it is hardened.

## Out of scope

- Rendering the catalogue for the citizen. portaliq owns the portal.
- The case type's internal configuration. dossiq owns the case type; this
  publishes it and imports definitions for it.
- The transport to the national catalogue. integriq owns the gateway,
  the same split as cluster 50.
