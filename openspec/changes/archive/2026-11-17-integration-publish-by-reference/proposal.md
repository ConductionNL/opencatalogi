---
kind: code
depends_on: []
---

# Proposal: integration-publish-by-reference

## Why

Some organisations keep the published document in their document management system and do not want a second copy in the publication app. Today every document OpenCatalogi publishes is a file stored in OpenRegister, so publishing always means copying.

opencatalogi matrix, row `int-link-not-copy`, "Publish a document by pointing at it in the source system, without copying the file into the publication platform." Own rating no, built.state none.

- Demand: tender, TenderNed 407973 (https://www.tenderned.nl/aankondigingen/overzicht/407973). Origin note: "TenderNed 407973 wish DMK-07 and requirement "the publication service does not store documents itself"".
- Own evidence: "attachments are always uploaded into OpenRegister file storage: the frontend posts files to /api/objects/{register}/{schema}/{id}/files and filesMultipart (src/modals/generic/UploadFiles.vue:1031,1265), and the legacy lib/Service/FileService.php:183-215 writes the upload into Publicaties/<pub>/Bijlagen; the DIWOO sitemap loc is the stored file's downloadUrl (lib/Service/SitemapService.php:512). Searched lib and src for accessURL, downloadURL, externalUrl, sourceUrl and remoteUrl: the only hits are the DCAT distribution mapping (lib/Service/DcatMappingService.php:369-370), which serialises a stored file's URL. Integriq synchronisations copy files too".

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "the resource form offers Link as well as Upload: 'Link to a URL on the internet' (ckan/templates/package/snippets/resource_upload_field.html:54-58, url_type empty for links :99-100), so the resource points at the source URL and nothing is stored in CKAN" |
| DKAN | partial | "a distribution's downloadURL uses the upload_or_link widget (schema/collections/dataset.ui.json:168-174), so a dataset can point at a remote file; but tabular distributions are fetched into local storage for the datastore" |
| xxllnc Publiceren | partial | "https://xxllnc.nl/applicaties/publiceren/ FAQ "Ontdubbeling en verwijzing": documents that were already published are recognised and the platform can "verwijzen naar de eerder gepubliceerde documenten"" |
| Decos JOIN Woo Portaal | partial | "https://decos.com/oplossingen/woo-portaal: "Je hoeft je informatie niet op meerdere plekken te beheren. Alles staat in JOIN, de publicatie gebeurt vanuit dezelfde bron"" |

The row is `build` under the rule "a tender demand row".

## What changes

- A publication gets document references beside its files: a public URL in the source system, a title, a format and the same publication window a file has.
- The DiWoo sitemap, the DCAT feed and the public publications API list a reference as a document, pointing at the source URL. Nothing is copied.
- A daily check reads each reference's URL and marks the ones that no longer answer, so an editor sees a broken link before a reader does.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `int-link-not-copy` | Publish a document by pointing at it in the source system, without copying the file into the publication platform. | no | every published document is a stored copy |

## Existing work it builds on

- Open change `attachments-are-files`: a document is a file on its publication with its own publication window. A reference follows the same window rules.
- Main spec `woo-compliance` (WOO-002, WOO-006, WOO-010) for the sitemap document, and main spec `dcat-ap-harvest` (DCAT-006, distributions) for the feed.
- `DirectoryService::validateOutboundUrl()` (`lib/Service/DirectoryService.php:1845`), the SSRF guard the reachability check reuses (ADR-054).

## Out of scope

- Reading or indexing the text of a referenced document. Full-text search covers stored files only.
- Proxying the referenced file through OpenCatalogi.
- Private or signed-in source URLs. A reference must be readable by the public, or it is not a publication.

## Sibling halves

- ConductionNL/integriq: a synchronisation copies files today (`filesPending` on the synchronisation run). A reference mode that writes a `documentReference` instead of a file is integriq's to add; this change gives it the schema to write to.
