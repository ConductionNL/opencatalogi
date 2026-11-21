# Council documents from Notubiz and iBabs

OpenCatalogi can read the public documents of the municipal council from the council information system (raadsinformatiesysteem) into the **Raadsinformatie** catalogue, every night. The documents arrive as concept publications with Woo category `infocat008` (raadsstukken), so someone checks each one before it is public. The harvest runs through integriq.

## Set it up

1. In integriq, fill in the source of your council system:
   - **Notubiz** (`notubiz-ris-v1`): the organisation id.
   - **iBabs** (`ibabs-ris-v1`): the API key and the organisation id.
2. In the OpenCatalogi admin settings, section **Council documents**, choose the source and press **Set up**. OpenCatalogi writes the synchronization and the file rule into integriq. A source without the setting it needs is refused with a message naming that setting, for example "Fill in the iBabs key in integriq first."
3. Press **Switch on**. The harvest then runs every night at 02:30, and **Run now** starts a run straight away.

**Open the source in integriq** takes you to the source to change its settings. **Last run** shows when the harvest last ran and how it went.

## What a run does

For every document the council system marks public, with a title and a file, a run:

- writes a publication with the document title, the meeting body and date as its summary (`{body}, meeting of {date}`), Woo category `infocat008` and the source identifier `notubiz:{id}` or `ibabs:{id}`;
- attaches the document file through integriq.

A confidential document is never written. A document that was seen before is updated, not added again: the write matches on the source identifier.

## Publish on arrival

Off by default: every document arrives as a concept. Switch **Publish on arrival** on when the council system is your source of truth; the publication then keeps the publication date the council system gives it.

## Only one source

Only one council system runs at a time: switching one on switches the other off. When decidiq also publishes council papers into this catalogue, the section warns that running both creates duplicates.

## What it does not do

A document the council system removes is not withdrawn automatically. Withdraw it in OpenCatalogi when that is right.

## For developers

| Piece | Where |
| --- | --- |
| Flows, mappings, Raadsinformatie catalogue | `lib/Settings/register.d/council-documents-harvest.json` |
| Synchronization and file rule templates | `lib/Settings/council-documents-sources.json` |
| Service | `lib/Service/CouncilHarvestService.php` |
| Admin routes | `GET /api/settings/council-harvest`, `POST .../setup` (`source`), `.../enable`, `.../disable`, `.../publish-on-arrival` (`publish`), `.../run` |

Specification: `openspec/changes/council-documents-from-notubiz-and-ibabs`.
