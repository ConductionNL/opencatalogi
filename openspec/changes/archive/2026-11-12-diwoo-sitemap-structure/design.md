# Design: diwoo-sitemap-structure

## Source of truth

The DiWoo 0.9.8 XSD (vendored by #2342 under `tests/Unit/Service/fixtures/diwoo-0.9.8/`, hash-checked) defines `diwoo:Document` and `diwoo:DiWoo`. `DiWooType` is an `xs:all`, so the order of the elements inside `diwoo:DiWoo` is free; the mandatory ones are `publisher`, `titelcollectie` (with `officieleTitel`), `classificatiecollectie` (with `informatiecategorieen`) and `documenthandelingen` (each `documenthandeling` with `soortHandeling` and an `xs:dateTime` `atTime`).

The `url` around it is the sitemaps.org `tUrl` type: a sequence of `loc`, then the optional `lastmod`, `changefreq`, `priority`, then elements of other namespaces. So the order `loc`, `lastmod`, `diwoo:Document` is fixed. The standard's own example sitemaps (`https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xml/sitemap-alleenverplicht.xml` and `sitemap-uitgebreid.xml`) use exactly this shape, with a `urlset` root and `lastmod` as `2022-09-02T00:00:00+02:00`.

## Shape of the mapped entry

`SitemapDocumentMapper::map()` returns one `url` entry: `['loc' => ..., 'lastmod' => ..., 'diwoo:Document' => ['diwoo:DiWoo' => [...]]]`. PHP arrays keep insertion order and `XMLResponse` writes keys in that order, which gives the sequence the sitemaps.org schema requires. `SitemapService::buildSitemap()` puts the entries under `url` of a `urlset` root. Callers that read `['diwoo:Document']['diwoo:DiWoo']` keep working.

## Dates

`lastmod` and `atTime` go through one helper that parses with `DateTimeImmutable` in UTC and formats `DATE_ATOM`. A value that carries an offset keeps its instant; a value without one is read as UTC. Reading it in the server's zone would make the output, and the goldens, differ between this machine (Europe/Amsterdam) and CI (UTC). `creatiedatum` stays an `xs:date` (`Y-m-d`).

## Title

`officieleTitel` is the publication's `title`, trimmed. Without a title the element is left out and the dry-run validator lists axis `officieleTitel`, as it does for the other mandatory axes. The document stays on the page: leaving it out is REQ-DWP-004 of `diwoo-metadata-on-the-publication`, and it changes which documents get published.

## Golden

`testEveryDocumentOfAPageValidatesAgainstTheDiwooXsd` renders a page through `buildSitemap()`, checks the `url` structure, and validates every `diwoo:Document` against the vendored XSD with the MDTO import mapped to its local copy. It asserts zero errors per document. The recorded-errors fixture of #2342 is removed: there is nothing left to pin. The same harness validates the two official example sitemaps (vendored verbatim, hash-checked) as a control: their only errors are the fictitious municipalities `gm9999` and `gm9998`, which the organisation list does not hold. That those errors appear also proves the validation runs.

An empty page was a `diwoo:Documents` holding one empty `diwoo:Document`; it is now a `urlset` without children.

## Risks

- A harvester that parsed the old `diwoo:Documents` root breaks. The KOOP harvester reads the `urlset` shape; the old one was never valid for it.
- `title` is now in the public sitemap. It is the title of a publication that is already public through the publications API, read in the same RBAC-scoped search.
