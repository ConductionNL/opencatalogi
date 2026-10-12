---
kind: code
depends_on: [woo-request-intake-hands-over-to-dossiq]
---

# Proposal: woo-request-screens-move-to-dossiq

## Summary

OpenCatalogi stops drawing screens for handling Woo requests, publishes what dossiq hands it, and points every request row at dossiq.

- Decision: Ruben, 2026-10-09. "OpenCatalogi now has screens for handling Woo requests, but that functionality belongs in dossiq as a case type; we can work together with citizens on their Woo dossier." This extends D1 (2026-10-05, dossiq owns the request, its intake and its term) from the intake to the screens.
- Counterparts:
  - `dossiq/woo-dossier-shared-with-the-requester` (new, this round): the Woo case type opens its dossier to the requester.
  - `portaliq/woo-dossier-in-my-cases` (new, this round): the requester works on that dossier in Mijn zaken.
  - `dossiq/woo-publish-decision-from-the-case` (open): dossiq publishes the decision and its documents into OpenCatalogi.
  - `dossiq/woo-decision-records-what-was-withheld` with `opencatalogi/woo-decision-shows-what-was-withheld` (open): the withheld list.
  - `opencatalogi/publication-tells-its-source` (open): OpenCatalogi tells dossiq where the publication went.
  - `opencatalogi/woo-request-intake-hands-over-to-dossiq` (open): forwards the intake, migrates the stored requests, then removes the request code. This change does not repeat that work.
- Design boards: `opencatalogi/OcWooVerzoek` and `opencatalogi/OcWooVerzoeken` are retired in design-system. Their content lives on `dossiq/DqWooVerzoeken`, `dossiq/DqZaak`, `dossiq/DqTermijnen` and `dossiq/DqPubliceren`. `opencatalogi/OcWooBatchAanmaken` loses its "Gekoppeld Woo-verzoek" field.

## Why

The design canvas drew two officer screens for Woo requests in OpenCatalogi: a list with terms met and missed, and one request with its term, extension and pause. The code never built them. `src/manifest.json` has no Woo request page and no menu entry; the request lives behind seven API routes under `/api/woo/requests` (read on `development` at 2467db849).

Ruben decided on 2026-10-09 that handling a Woo request is dossiq's work, as a case type. dossiq already has the screens. So the two boards go, and nothing in OpenCatalogi may grow back into them.

Three things stay in OpenCatalogi: the disclosure batch (`OcWooBatches`, `OcWooBatch`, `OcWooBatchAanmaken`), the Woo obligations (`OcWooVerplichtingen`), and publishing the decision and its documents.

What each OpenCatalogi request screen showed, and where it lives now:

| shown on the OpenCatalogi board | where it lives now |
|---|---|
| request list with reference, received, due date, days left | `DqWooVerzoeken` (columns received, due, days left) |
| views Bezig, Wacht op verduidelijking, Te laat, Besloten | `DqWooVerzoeken` views Binnen termijn, Opgeschort, Te laat, Gepubliceerd |
| terms met and missed this year | `DqTermijnen` ("beslist binnen de wettelijke of verlengde termijn") and dossiq's term report (`woo-term-is-computed-and-reported-right`, row 16.2) |
| linked batch per request | `DqWooVerzoeken` column Publicatie, and the case's `wooPublicationUrl` |
| one request: reference, received, requester, channel, legal ground, handler | `DqZaak` (Verzoeker, Kanaal, Wettelijke termijn) |
| one extension of 14 days, a pause while clarification is awaited | `DqZaak` (Termijn opschorten) and `DqTermijnen` (verdagen) |
| next step: create the Woo batch | `DqPubliceren` ("Publiceer het besluit en de openbare documenten") |

## What changes

1. OpenCatalogi offers no officer screen for Woo requests: no page, no menu entry, no dashboard widget (REQ-WRS-001). A unit test guards the manifest.
2. The parity rows `wr-request-record` and `wr-search-sources` carry `screen: {board: null, reason: ...}` and name the dossiq boards. Neither keeps an OpenCatalogi board.
3. A disclosure batch names the Woo case it answers through `caseReference`, the dossiq case (REQ-WRS-002). OpenCatalogi offers no picker for its own requests. The `attachBatch` route goes with the removal in `woo-request-intake-hands-over-to-dossiq` group 6.
4. The inbound contract is written down (REQ-WRS-003). dossiq publishes a Woo decision as one `publication` with `publicationKind` `woo-besluit`, `caseReference`, `wooCategory` and an optional `period`, with the disclosable documents as files on it. OpenCatalogi keeps those properties declared.
5. The requester follows the request in portaliq Mijn zaken, on the dossiq case (REQ-WRS-004). OpenCatalogi shows a requester no request status. "Mijn dossiers" keeps the dossiq action to start a request, and keeps showing publications.

## Out of scope

- Forwarding the intake, migrating stored requests and removing the request code: `woo-request-intake-hands-over-to-dossiq`.
- The term engine. Another lane reworks dossiq's term engine and retires `WOODeadlineService`.
- The batch screens and the obligations screen. They stay as drawn.

## Release notes

- OpenCatalogi no longer shows Woo requests to officers. Handle them in dossiq, as a case.
- Publishing a Woo decision and its documents stays in OpenCatalogi.
