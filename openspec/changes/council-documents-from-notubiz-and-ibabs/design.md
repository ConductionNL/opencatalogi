# Design: council-documents-from-notubiz-and-ibabs

Read at opencatalogi development `0d89f9dd5` and integriq `development` on 7 October 2026. Board: `OcInstellingen` on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a). Its "GitHub harvest" section reads "Reads publiccode.yml files from a GitHub organisation into the software catalog. Runs through integriq." with Source, Last run, and the actions Run now, Switch off and Open the source in integriq. The new "Council documents" section uses the same fields and actions, placed after it. The board does not show the section yet.

## Where it lands

| piece | file | precedent |
|---|---|---|
| flow, mapping, catalogue | `lib/Settings/register.d/council-documents-harvest.json` | `publiccode-github-harvest.json` (flow on a schema, `mappings`, seeded `objects`) |
| service and routes | `lib/Service/CouncilHarvestService.php`, `lib/Controller/CouncilHarvestController.php`, `/api/settings/council-harvest[...]` | `PubliccodeHarvestService` `status`, `setUp`, `setEnabled`, `runNow`; routes at `appinfo/routes.php:81-85` |
| sources | integriq `notubiz-ris-v1`, `ibabs-ris-v1` | `configurations/decidesk-ris-import/sources/` |
| file fetch | integriq `openconnector.fetch-file` with a `fetch_file` rule | `lib/Flow/FetchFileNode.php` |

## D1. One flow per source kind

The flow "Council documents harvest" is declared on the `publication` schema, `enabled: false`, triggers `openregister.trigger-schedule` (cron `30 2 * * *`, `runAs` set at setup) and `openregister.trigger-manual`. Nodes:

1. `openconnector.source-paginate` on synchronization `opencatalogi-council-documents` (Notubiz: endpoint `/documents` for the organisation, recent meetings first; iBabs: the documents of each meeting), output `page`;
2. `openregister.explode` over the page's documents;
3. `openregister.filter`: only documents the source marks public, with a title and a file URL;
4. `openregister.map` with mapping `council-document-to-publication`;
5. `openregister.object-write` upsert into register `publication`, schema `publication`, matched on the source identifier (D3);
6. `openconnector.fetch-file` with rule `opencatalogi-council-document-file`, attaching the document file to the written publication.

`setUp()` writes the synchronization and the rule for the source kind the administrator picked, the same way `PubliccodeHarvestService::setUp()` writes its shard synchronizations. They are not seeded, because OpenRegister skips an object whose register is missing on an instance without integriq.

## D2. The mapping

| publication property | from |
|---|---|
| `title` | the document title |
| `summary` | "{body}, meeting of {date}" |
| `wooCategory` | `infocat008` |
| `publicationDate` | empty (concept) unless "Publish on arrival" is on, then the source's publication date |
| `caseReference` (later `sourceIdentifiers`) | `notubiz:{id}` or `ibabs:{id}` |
| `organization` | the catalogue's organisation |
| `catalog` | the seeded "Raadsinformatie" catalogue |

## D3. Match on the source identifier

The upsert matches `caseReference` until `publication-relations-place-and-source-ids` lands, then `sourceIdentifiers` with system `notubiz` or `ibabs`. A run never creates a second publication for a document it has seen.

## D4. Concept by default

A harvested publication arrives as a concept, so a person checks it before it is public. "Publish on arrival" in the settings card turns that off for organisations whose council system is the source of truth.

## D5. The settings section

Section "Council documents" on `OcInstellingen`: Source (an `NcSelect` with `inputLabel` listing integriq sources of type Notubiz or iBabs), Publish on arrival (switch), Last run with counts new, updated and failed, and the actions Set up, Run now, Switch off and Open the source in integriq. When decidiq's `PublicationPayload` already writes into the Raadsinformatie catalogue, the section shows "decidiq already publishes council papers here. Running both creates duplicates."

## Risks

- iBabs needs a key per organisation. Without one, `setUp()` refuses with "Fill in the iBabs key in integriq first" and links the source.
- A council system that removes a document. This change does not withdraw on removal; the run report lists documents not seen for 30 days, so a person decides.
