---
kind: code
depends_on: [the-public-and-community-surface]
---

# Proposal: publication-inspection-and-the-national-indexes

Round 4 discovery sweep, cluster 50 "Publication, inspection and the
national indexes" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner opencatalogi, size
L, no decision named, depends on the public and community surface.
Candidates: `C-decisions-6`, `-11`, `-12`, `-14`, `-15`, `-18`, `-19`,
`-20`, `-21`, `-23`, `-24`, `-26`, `-27`, `-28` and `C-search-36`
(`candidates.json`, lane lines `decisions.tsv:7` to `:31` and
`search.tsv:28`).

## Summary

Publishing is a legal act with a beginning, a window and an end.
opencatalogi holds sitemaps, DiWoo metadata and a harvester readiness
check. What it does not hold is the act: who decided to publish, which
documents went, for how long, through which national channel, and how to
take it back.

## Why

Eleven of the fifteen candidates are `must`, more than any other
opencatalogi cluster, and every one of them names a statutory duty.

- **Woo actieve openbaarmaking** is "this record type, these parts, no
  login". The lane checked and found `PublicationController.php`
  publishes chosen objects, not a record type with a permission set.
- **Terinzagelegging** is a statutory step with a defined window, and the
  lane says it "exists nowhere in our 273 rows".
- **Bekendmaking** of a besluit is a legal step, and the sweep found it
  held "in twelve files without an LVBB route".
- **Publicatieplicht** is a property of the besluittype, and the
  validation is what stops an unpublished besluit closing a case.
- **The zienswijze of a third party before openbaarmaking** is an Awb
  obligation on a Woo decision, and nothing in 273 rows asks about it.

dossiq rates ten of the fifteen `no` and five `partial`. Its partials are
all pointers back here: `WOOAssessmentController.php`, and twice the
single word `opencatalogi`.

## The passers that prove it, and what kind of evidence they are

Nine systems pass a member, **three driven and six documented**. Proving
system: taiga. This is the weakest evidence base of the three
opencatalogi clusters, and it is weak for a reason the sweep names: the
Dutch vendors publish product pages, not source. Under Ruben's answer to
D21, documented candidates are admitted and labelled, and under D6 every
`must` enters whatever the passer count. Every requirement below records
which kind of passer it rests on.

| candidate | relevance | driven | documented | evidence the lane cited |
|---|---|---|---|---|
| `C-decisions-6` | must | taiga | youtrack | `projects/models.py:221-226 is_private`, `anon_permissions` (`D-taiga-1`, `D-youtrack-30`) |
| `C-decisions-18` | should | xxllnc-zaken | | Case type > Documentatie (`case-type-editor-anatomy.md`, `D-xxllnc-64`) |
| `C-decisions-19` | must | dimpact-zac | | Besluiten (`decision-management/spec.md`, `D-dimpact-44`, `D-dimpact-45`) |
| `C-decisions-12` | must | | rx-mission | `/modules/` Inzien (`D-rxmission-12`, `D-rxmission-14`, `D-rxmission-16`) |
| `C-decisions-28` | must | | rx-mission | `/modules/` Inzien (`D-rxmission-13`) |
| `C-decisions-11` | must | | mozard | `/integraties` (`D-mozard-17`) |
| `C-decisions-14` | must | | decos-join | `/oplossingen/woo-portaal` (`D-decos-18`, `D-decos-19`) |
| `C-decisions-27` | should | | decos-join | `/oplossingen/woo-portaal` (`D-decos-20`) |
| `C-decisions-15` | must | | visma-circle | `/software/open-overheid` (`D-visma-19`) |
| `C-decisions-20` | must | | visma-circle | `/software/open-overheid` (`D-visma-17`) |
| `C-decisions-24` | must | | visma-circle | `/software/open-overheid` (`D-visma-18`) |
| `C-decisions-26` | must | | visma-circle | `/software/open-overheid` (`D-visma-21`) |
| `C-decisions-23` | should | | visma-circle | `/software/open-overheid`, Djuma OpenInfo (`D-visma-16`) |
| `C-decisions-21` | should | | pinkroccade-izaaksuite, visma-circle | `/proces-services/wet-open-overheid/` iOpenbaar (`D-pinkroccade-16`, `D-visma-20`) |
| `C-search-36` | must | | visma-circle | `/software/open-overheid` (`D-visma-22`) |

## What opencatalogi builds

- **Anonymous read of a record type with a chosen permission set.** Not
  a list of objects somebody picked. A record type, a set of visible
  parts, and no login.
- **Publication declared on the type**, with its publication text, and
  the decision type's rules validated on the decision: the publication
  date, and the statutory response date computed from it.
- **Terinzagelegging**: documents on public inspection for exactly the
  statutory period, with the link expiring with the window, available to
  any record type that needs it, and the documents that form the
  inspection set chosen when the publication is made.
- **Depublication in one action**, with the reason recorded.
- **The national channels**, through integriq's gateway: the official
  notice to the national publication platform and the local channel, and
  the connection to the national Woo index.
- **Publication as a walked process**, with a check before a zienswijze
  round and an approval, rather than a button.
- **The zienswijze round**: interested parties consulted over a secure
  channel before information about them is published.
- **A digital stamp of authenticity** on the published document.
- **One publication overview**, fed from every application and from other
  case systems, not only from ours.
- **Public search over published information**, in plain words, showing
  which dossier a document belongs to.

## How dossiq consumes it

dossiq declares publication on the case type and the decision type, hands
over the decision and the documents chosen for inspection, and reads the
publication state back. It does not hold a Woo portal, an inspection
window or a national connection. Its `WOOAssessmentController` keeps the
Woo request; the publication of the answer comes here.

## The gateway half is integriq's

`C-decisions-11` and `C-decisions-21` reach outside: the national
publication platform (LVBB) and the national Woo index. The plan's
mechanism line is explicit: opencatalogi holds the Woo and DiWoo
publication, integriq holds the gateway. This change specifies what is
sent, when, and what comes back. It does not specify the transport.

## Existing specs it extends

`woo-compliance` (the DiWoo mapping, the TOOI value lists, the sitemaps
and the harvester readiness check), `publications` (the public read
surface, publish and depublish), `catalogs`, `auto-publishing`,
`publication-retention-lifecycle` (the window and what happens at its
end), `woo-transparency` and `search` (the public search).

## ADRs

- ADR-031: publication rules, windows and permission sets are declared
  data.
- ADR-054: every surface added here is public and hardened.
- ADR-011: no second BSN, date or slug utility. Reuse OpenRegister's.

## Out of scope

- What a besluit contains. dossiq owns the decision.
- The transport to LVBB and the Woo index. integriq owns the gateway.
- Archiving after the window. `publication-retention-lifecycle` and
  filinq own what happens to a record when its retention runs out.
