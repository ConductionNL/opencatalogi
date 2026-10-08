---
kind: code
depends_on: []
---

# Proposal: council-documents-from-notubiz-and-ibabs

## Why

The council clerk's office publishes the council papers in the council information system, Notubiz or iBabs. The Woo says those papers are also actively published, under the category "Vergaderstukken decentrale overheden". Today someone copies them into OpenCatalogi by hand, or the catalogue links out to the council site, which the national Woo index does not follow.

opencatalogi matrix, row `int-council`, "Take council documents from a council information system such as Notubiz or iBabs." Own rating no, built.state `none`, owner `ConductionNL/integriq`.

- Own evidence: "integriq lib/BackgroundJob/RISPollJob.php polls iBabs/NotuBiz for updates on ris_sync_record objects this side already synced (outbound besluit sync); grep of opencatalogi lib/ for notubiz|ibabs finds nothing".
- Read on integriq `development`: `configurations/decidesk-ris-import/` ships the Notubiz and iBabs sources (`notubiz-ris-v1`, `ibabs-ris-v1`) and imports meetings, agenda items, decisions and votes into decidiq. Nothing imports the documents into a catalogue.
- Decos JOIN is the one competitor rated yes; integriq's gap decision on 27 Sep deferred it for that reason. The row's state `none` puts it in this round.

## What changes

- OpenCatalogi ships a "Council documents" harvest, disabled by default, next to the GitHub harvest in the admin settings.
- An administrator picks the integriq source (Notubiz or iBabs), sets it up, and switches it on. It runs every night and on demand.
- Each published meeting document becomes a publication in a "Raadsinformatie" catalogue, with its meeting, date and body, Woo category `infocat008`, and the file attached. It arrives as a concept unless the administrator chose to publish on arrival.
- A document seen before is updated, not duplicated, by its source identifier.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `int-council` | Take council documents from a council information system such as Notubiz or iBabs. | no | no flow, no mapping, no catalogue, no settings |

## Existing work it builds on

- `publiccode-github-harvest` (open change here, built): a flow declared on a schema in `lib/Settings/register.d/`, integriq sources and synchronizations, and a settings card with set up, switch on, run now. This change copies that shape.
- integriq's Notubiz and iBabs sources in `configurations/decidesk-ris-import/sources/`, and its `openconnector.fetch-file` flow node, which attaches a file to an object after it is written.
- `publication-relations-place-and-source-ids` (open change here): `sourceIdentifiers` on the publication. Until it lands, the match key is `caseReference` with the prefix `notubiz:` or `ibabs:`.

## Out of scope

- Meetings, agenda items, decisions and votes as structured data. decidiq imports those.
- GemeenteOplossingen. integriq's `decidesk-ris-import-go-investigation` has not found its API yet.
- Organisations that already publish council papers through decidiq. The settings card warns when decidiq's publication payload already feeds the target catalogue.
