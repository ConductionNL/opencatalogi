# Design: oai-pmh-endpoint

## Context

The live DCAT surface (`lib/Service/DcatService.php`,
`lib/Controller/DcatController.php`, `lib/Service/DcatMappingService.php`)
already solves the hard parts an OAI-PMH endpoint needs: which catalogs are
feed-enabled (`DcatService::getDcatEnabledCatalogs()`), which objects are
publicly visible (DCAT-003 — the OR public-RBAC predicate, never an app-local
filter), how a publication maps to a dataset node (`x-dcat` annotation,
DCAT-004) and harvester-grade pagination + caching (DCAT-008). OAI-PMH is a
second projection of the same rows.

## Decisions

1. **One new service, one new controller.** `OaiPmhService` renders verb
   responses from the same query surface `DcatService::buildCatalogDocument()`
   uses; `OaiPmhController` handles `GET /catalog/{slug}/oai`, `#[PublicPage]`
   + `#[NoCSRFRequired]` like `DcatController`. No changes to DcatService
   beyond extracting any shared private helper into a small trait or
   collaborator IF review shows literal duplication (do not pre-abstract).
2. **Stateless resumption tokens.** `base64url(json{cursor, set, from, until,
   prefix, expires})`, HMAC'd with the instance secret so a tampered token is
   `badResumptionToken`, not a crash. No token table — the umbrella's stored
   token state was cut in the re-scope.
3. **oai_dc mapping derives from the DCAT node**, not from the raw object:
   title→dc:title, description→dc:description, publisher→dc:publisher,
   modified→dc:date, distributions→dc:format/dc:identifier, landing
   page→dc:identifier, license→dc:rights. One mapping table in the service,
   unit-tested field by field.
4. **Tombstones from depublication.** A record inside the `from`/`until`
   window that is no longer publicly visible but has a `depublicationDate`
   emits `<header status="deleted">` (deletedRecord: transient). We do not
   track deletions we cannot see; Identify declares `transient`, not
   `persistent`.
5. **XML rendering via XMLWriter** (already a dependency of the sitemap
   path), never string concatenation; every value escaped by the writer.
   ⚠️ CI note: tests load XML fixtures via `file_get_contents` +
   `simplexml_load_string` (the NC tree's null entity loader breaks
   `simplexml_load_file` on ANY file).

## Verb/argument matrix (normative summary; the delta spec carries scenarios)

| Verb | Required args | Optional args | Errors exercised |
| --- | --- | --- | --- |
| Identify | — | — | badArgument |
| ListMetadataFormats | — | identifier | idDoesNotExist |
| ListSets | — | resumptionToken | badResumptionToken |
| ListIdentifiers | metadataPrefix | from, until, set, resumptionToken | cannotDisseminateFormat, noRecordsMatch |
| ListRecords | metadataPrefix | from, until, set, resumptionToken | cannotDisseminateFormat, noRecordsMatch, badResumptionToken |
| GetRecord | identifier, metadataPrefix | — | idDoesNotExist, cannotDisseminateFormat |

## Risks

- OAI-PMH identifier scheme must be stable forever: use
  `oai:{host}:{catalogSlug}/{uuid}` derived from the DCAT dataset IRI
  (`DcatService::datasetIri()`), never a database id.
- Harvesters poll aggressively: reuse DCAT-008's cache-validator discipline
  on Identify/ListRecords responses.
