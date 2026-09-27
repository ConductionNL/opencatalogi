# Design: woo-sitemap-document-signals

Read at opencatalogi development `1694b051`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| sitemap page | `lib/Service/SitemapService.php:297` `buildSitemap()` | loads each publication's files through OpenRegister's `FileService::getFiles()` and `formatFiles()` and maps every file with a `downloadUrl` separately (:338-350) |
| document mapping | `SitemapService.php:508` `mapDiwooDocument()` | `$published = ($file['published'] ?? $updated)` (:512), `'lastmod' => date('Y-m-d H:i:s', strtotime($published))` (:526), returns `['diwoo:Document' => ['diwoo:DiWoo' => $diwoo]]` (:588) |
| file fields | OpenRegister `FileFormattingHandler` (development, read 2026-09-27) | each formatted file carries `modified` (upload time), `published`, `depublished` and `labels` |
| schema location | `SitemapService.php:352-355` | the DiWoo metadata XSD `https://standaarden.overheid.nl/diwoo/metadata/0.9.1/xsd/diwoo-metadata.xsd` |

## D1. lastmod is the later of two times

`lastmod` = the latest of the file's `modified`, the file's `published`, and the publication's `@self.updated`. A metadata edit on the publication moves `@self.updated`, so every document of that publication moves with it, which is what the harvester needs: the DiWoo metadata of each document is the publication's metadata.

The format stays as it is today. No change to the page-level `lastmod` of the sitemap index.

## D2. isPartOf on every document of a multi-file publication

When a publication has two or more mapped files, each document gets `isPartOf` pointing at the publication's stable public API URL, `{baseUrl}/apps/opencatalogi/api/{catalogSlug}/{publicationId}` (the route `publications#show` already serves). A single-file publication gets no relation element.

## D3. A main document is a file label

An editor marks the main document by giving the file the label `main-document` in the Attachments section of the publication page. The Attachments section is OpenRegister's files integration, which already edits labels, so no new screen is needed. When exactly one file carries the label:

- the main document gets `hasParts` with one `hasPart` per other file, each with the other file's `loc` as its resource;
- every other file's `isPartOf` points at the main document's `loc` instead of the publication URL.

When no file or more than one file carries the label, D2 applies and the DiWoo validator (WOO-TOOI-004) reports the publication once with the reason, so an editor can fix it.

## D4. Element names come from the XSD, not from memory

The element names (`diwoo:isPartOf`, `diwoo:hasParts`, `diwoo:hasPart`) and their position inside `diwoo:DiWoo` are taken from the DiWoo metadata XSD the sitemap already references. Task 1.1 reads the XSD and pins the names in a unit test fixture before any output changes.

## Declarative or imperative

The sitemap is generated output. There is no lifecycle, aggregation, notification or widget, and no schema change. The marker is a file label, which is data, not code.

## Seed data

None. The register's seed objects (`lib/Settings/publication_register.json`, two publications) carry no files, and files cannot be seeded through the register JSON. The relations are checked on a test publication by hand (task 4.1).

## Risks

- Any save of a publication moves `@self.updated`, including the retention job's `retentionLastEvaluatedAt` write. The harvester then reads a document again that did not change for a reader. That costs one extra harvest, never a missed change.
- A harvester that ignores `hasPart` still reads every document as today.
