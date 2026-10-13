---
kind: data
depends_on:
  - openregister/example-sets (ConductionNL/openregister#4603)
---

# Proposal: zuiddrecht-example-set

## Summary

OpenCatalogi ships its part of the Zuiddrecht example set. One command on a fresh instance then shows a catalog full of Wet open overheid (Woo) publications: `occ openregister:example-set:install zuiddrecht`.

## Why

The Zuiddrecht demo tells one story. Sanne de Vries asks for the documents about the new lighting on the cycle path at the Lindelaan. The site has a news item about it. A visitor who searches the Woo publications today finds two placeholder rows of a different municipality.

OpenRegister#4603 adds one loader for a story across apps. Each app ships `lib/Settings/example-sets/<set>.json`. The cast lives once, in OpenRegister. This change is opencatalogi's fragment.

## What changes

- `lib/Settings/example-sets/zuiddrecht.json`, a data file with 26 objects:
  - The catalog "Gemeente Zuiddrecht", slug `zuiddrecht`, with the withheld list shown.
  - 8 themes that follow the subjects of the Zuiddrecht site.
  - 13 published publications. Six follow the Lindelaan lighting: an inspection report, the advice of the neighbourhood council, the college decision, the council decision, a letter to the council and the contract with Installatiebedrijf Van Dam. One is the Woo decision on the case system tender. The others are a waste schedule, a parking tax ordinance, terrace rules, an event permit, the mandate decision and the complaints report.
  - 2 withheld documents on the Woo decision, with their refusal grounds.
  - Sanne's dossier "Fietspad Lindelaan" with three publications, and her saved search.
- Every publication uses an information category code from `tooi_waardelijsten.json`, except the contract, which has no Woo category.
- 20 demo documents as small PDF files in `lib/Settings/example-sets/zuiddrecht/<publication key>/`, made by `scripts/example-sets/make-zuiddrecht-documents.py`. Every page says it is demo content.

## The documents need a loader feature

OpenCatalogi serves a publication's documents as files on the publication object. The files must be shared to be public (`PublicationService::attachments()` lists `sharedFilesOnly`). The title, description and publication window sit on the file record. The loader of openregister#4603 writes objects only, so it cannot attach these files yet.

The request on the loader, in its smallest form: an object entry may carry `files`, a list of `{path, title, description, share}`, where `path` is relative to the app's `lib/Settings/example-sets/` folder. The loader adds each file to the object it just wrote with OpenRegister's file service, shared when `share` is true, and sets the description and label on the file record. Remove deletes them with the object. This change ships the files in the folder layout that request expects: one folder per publication key.

Until the loader can do this, the publications install without documents.

## Not in this change

- An organisation on the catalog and the publications. `organization` refers to OpenRegister's organisation entity, which a lookup cannot find.
- Rows in `informationCategory`. The 17 Woo categories come from the waardelijst and need no row.
