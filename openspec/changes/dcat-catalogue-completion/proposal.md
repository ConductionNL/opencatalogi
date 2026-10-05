---
kind: code
depends_on: []
---

# Proposal: dcat-catalogue-completion

## Why

The DCAT feed is what puts a catalogue on data.overheid.nl and, through it, on data.europa.eu. Three things are missing to make that complete: a licence that the national portal can read as a licence, a record of whether the national portal actually harvests us, and a way to query the catalogue as linked data rather than only download it.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **8.5** "A dataset licence comes from a controlled list". Ours: partial, production. Evidence: "lib/Service/DcatMappingService.php:56 maps dct:license from a publication or catalogue default; the value is free text rather than drawn from a controlled list".
- **8.6** "The catalogue is harvested into the national open data portal". Ours: partial, production. Evidence: "the DCAT endpoints are public and CORS enabled so the national portal can harvest them, and lib/Service/WooRegistrationService.php tracks the Woo-index registration. Nothing tracks a data.overheid.nl registration or its harvest result".
- **8.8** "A linked data or SPARQL endpoint serves the catalogue". Ours: no, roadmap. Evidence: "zero SPARQL hits. lib/Service/DcatSerializer.php emits JSON-LD, RDF/XML and Turtle as documents, which is linked data serialisation without a queryable endpoint".

Read on development at 35999c296. The licence reaches the feed from `publication.license`, then the catalogue's `dcatLicense`, then the app config `dcat_default_license` (`DcatService` line 168, `DcatMappingService::mapDataset()`), and is emitted as `{'@id': <value>}` whatever the value is. `DcatVocabularyService` already resolves two controlled lists (HVD categories, data themes) from `lib/Settings/dcat_waardelijsten.json`, with the bind-or-omit rule of DCAT-NPF-003. `DCAT-NPF-001` keeps registration with data.overheid.nl operator-driven; there is no push. The serialiser is hand-written; the app has no RDF library.

## What changes

- The licence binds to a controlled list: the EU licences authority list (`http://publications.europa.eu/resource/authority/licence`) as data.overheid.nl accepts it, bundled in `dcat_waardelijsten.json` and resolved by `DcatVocabularyService::resolveLicence()`. The admin and catalogue settings offer a picker instead of free text. A value that does not resolve is omitted and reported, exactly as DCAT-NPF-003 does for themes.
- The DONL registration is tracked beside the Woo-index registration: the operator records requested and registered with the date; the app records every fetch of `/api/dcat` by the DONL harvester (by its user agent); and a daily job asks the DONL CKAN API how many datasets it holds from our source, stores the count and any error, and compares it with what the feed carries.
- Every dataset and catalogue IRI dereferences: `GET` with an `Accept` of Turtle, RDF/XML or JSON-LD answers that one resource's graph. A read-only SPARQL 1.1 query endpoint at `/api/sparql` serves the published DCAT graph.

## Fail closed

- A licence that does not resolve is never emitted as a literal or an unchecked IRI. It is omitted from the feed and listed by the validator.
- The registration status never reads "harvested" on the operator's word alone. "Harvested" needs an observed fetch by the harvester or a non-zero count from the DONL API. When the API cannot be reached, the status says so and keeps the last known count with its date.
- The SPARQL endpoint answers only from the published graph, which is built from the same anonymous public read as `/api/dcat`. It refuses SPARQL Update, `SERVICE` and `LOAD`, applies a result limit and a time limit, and answers 400 on a query it cannot parse.

## Out of scope

- Pushing a registration to data.overheid.nl. DCAT-NPF-001 keeps it operator-driven.
- A general SPARQL endpoint over every OpenRegister register. If OpenRegister later offers one, this endpoint can delegate to it.
- Licence per file beyond what the feed already maps per distribution.

## Dependencies

- None to build. The SPARQL engine is a library: the builder adds a maintained, permissively licenced PHP SPARQL query engine that works over an in-memory graph (check `semsol/arc2` first; record the choice and its licence in the PR body). If none qualifies, stop and report; do not write a SPARQL parser.

## Wave

Wave 1. It needs nothing new.

## Decisions

None of D1 to D13 is implemented here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 8.5 | A dataset licence comes from a controlled list | partial | REQ-DCC-001, scenarios "A licence from the list" and "A free-text licence is not emitted" |
| 8.6 | The catalogue is harvested into the national open data portal | partial | REQ-DCC-002, scenario "The admin sees whether data.overheid.nl harvests us" |
| 8.8 | A linked data or SPARQL endpoint serves the catalogue | no | REQ-DCC-003, scenarios "A dataset IRI dereferences" and "A SPARQL query over the catalogue" |
