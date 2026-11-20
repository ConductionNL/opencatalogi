# Woo compliance: sitemaps and robots.txt

OpenCatalogi publishes every Woo catalogue as DiWoo sitemaps, so the national Woo-index (KOOP) and search engines can harvest the published documents, and points crawlers at them from `robots.txt`.

Spec: [`openspec/specs/woo-compliance/spec.md`](../../openspec/specs/woo-compliance/spec.md), change [`woo-compliance`](../../openspec/changes/archive/2026-11-20-woo-compliance/).

## Endpoints

All three are public (no login) and read only what the public group may read: every search runs with OpenRegister RBAC on.

| Endpoint | Answers |
| --- | --- |
| `GET /apps/opencatalogi/api/robots.txt` | `User-agent: *`, the `Allow` lines for the API, and one `Sitemap:` line per category for every catalogue with `hasWooSitemap: true` and a slug |
| `GET /apps/opencatalogi/api/{catalogSlug}/sitemaps/{categoryCode}` | The sitemap index of one category: one `<sitemap>` per page of publications |
| `GET /apps/opencatalogi/api/{catalogSlug}/sitemaps/{categoryCode}/publications?page=N` | One sitemap page: one `<url>` per published file, each with its `diwoo:Document` |

`{categoryCode}` is the sitemap file name of a category, such as `sitemapindex-diwoo-infocat014.xml`. An unknown code, or a catalogue without a Woo sitemap, answers 400.

Example: `GET /apps/opencatalogi/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml`

```xml
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc>https://example.org/apps/opencatalogi/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml/publications?page=1</loc>
    <lastmod>2026-11-02 09:15:00</lastmod>
  </sitemap>
</sitemapindex>
```

A page holds 1000 publications by default (app setting `sitemap_max_per_page`), newest update first; the index's `lastmod` for a page is the newest update on that page. A category without publications answers 200 with a `<sitemapindex>` without children.

## `hasWooSitemap`

A catalogue publishes Woo sitemaps only with `hasWooSitemap: true`. `robots.txt` lists the sitemaps of exactly those catalogues that also have a slug; a catalogue with `hasWooSitemap: false`, or without the field, adds no `Sitemap:` line and its sitemap endpoints answer 400.

## DiWoo metadata per document

Each downloadable file of a publication is one document.

| DiWoo field | Source | When it is missing |
| --- | --- | --- |
| `loc` | the file's `downloadUrl` | empty; files without a download URL are left off the page |
| `lastmod` | the latest of the file's `modified`, the file's `published` and the publication's `@self.updated` | now |
| `diwoo:creatiedatum` | the publication's `@self.created`, as a date | today |
| `diwoo:publisher` `#text` | the name of the publication's organisation, never a user | empty, and reported |
| `diwoo:publisher` `@resource` | the organisation's TOOI identifier (publication `tooiIdentifier`, a TOOI `@self.organisation`, or the OpenRegister organisation's TOOI) | omitted, and reported |
| `diwoo:format` | `@resource` the EU file type of the extension in capitals, `#text` the extension in lower case | — |
| `diwoo:informatiecategorie` | `wooCategory`, then `category`, `tooiCategorieUri`, `tooiCategorieNaam`, resolved to the TOOI value list | omitted, and reported |
| `diwoo:soortHandeling` | the publication's `soortHandeling`, resolved to the DiWoo value list; `ontvangst` when it has none | omitted, and reported, when it resolves to nothing |
| `diwoo:atTime` | the file's `published`, then the publication's `publicationDate` | now |
| `diwoo:officieleTitel` | the publication's `title` | omitted, and reported |

An axis that cannot resolve to an official URI is never written as free text: it is left out and reported by `GET …/sitemaps/{categoryCode}/validate`.

## Woo information categories

The 18 members of the TOOI waardelijst, one sitemap file each. A category an administrator adds locally gets its own file too.

| Code | Sitemap file | Category |
| --- | --- | --- |
| `infocat001` | `sitemapindex-diwoo-infocat001.xml` | Wetten en algemeen verbindende voorschriften |
| `infocat002` | `sitemapindex-diwoo-infocat002.xml` | Overige besluiten van algemene strekking |
| `infocat003` | `sitemapindex-diwoo-infocat003.xml` | Ontwerpen van wet- en regelgeving met adviesaanvraag |
| `infocat004` | `sitemapindex-diwoo-infocat004.xml` | Organisatie en werkwijze |
| `infocat005` | `sitemapindex-diwoo-infocat005.xml` | Bereikbaarheidsgegevens |
| `infocat006` | `sitemapindex-diwoo-infocat006.xml` | Bij vertegenwoordigende organen ingekomen stukken |
| `infocat007` | `sitemapindex-diwoo-infocat007.xml` | Vergaderstukken Staten-Generaal |
| `infocat008` | `sitemapindex-diwoo-infocat008.xml` | Vergaderstukken decentrale overheden |
| `infocat009` | `sitemapindex-diwoo-infocat009.xml` | Agenda's en besluitenlijsten bestuurscolleges |
| `infocat010` | `sitemapindex-diwoo-infocat010.xml` | Adviezen |
| `infocat011` | `sitemapindex-diwoo-infocat011.xml` | Convenanten |
| `infocat012` | `sitemapindex-diwoo-infocat012.xml` | Jaarplannen en jaarverslagen |
| `infocat013` | `sitemapindex-diwoo-infocat013.xml` | Subsidieverplichtingen anders dan met beschikking |
| `infocat014` | `sitemapindex-diwoo-infocat014.xml` | Woo-verzoeken en -besluiten |
| `infocat015` | `sitemapindex-diwoo-infocat015.xml` | Onderzoeksrapporten |
| `infocat016` | `sitemapindex-diwoo-infocat016.xml` | Beschikkingen |
| `infocat017` | `sitemapindex-diwoo-infocat017.xml` | Klachtoordelen |
| `infocat018` | `sitemapindex-diwoo-infocat018.xml` | Inspanningsverplichting art 3.1 Woo |

## Download URLs depend on auto-publishing

`loc` is the file's public share link. Files get one when they are published (see the auto-publishing feature); a publication whose files were never shared has no documents on its sitemap page.

## Default information categories

### The default category of a kind of object

Every document in a DiWoo sitemap carries an informatiecategorie from the official TOOI waardelijst. A publication normally names its own category. When it names none, OpenCatalogi looks for a **default for its kind of object** before it reports the category as missing.

These defaults are `WooCategoryMapping` objects in the `publication` register. Each row holds:

| Property | What it holds |
| --- | --- |
| `app` | The app that owns the kind of object, for example `opencatalogi` or `decidesk`. |
| `objectType` | That app's schema slug or payload kind, for example `publication` or `decision`. |
| `informatiecategorie` | The TOOI URI of the category, for example `https://identifier.overheid.nl/tooi/def/thes/kern/c_db4862c3`. |
| `informatiecategorieLabel` | The category's label, shown beside the URI so a person can check it. |
| `active` | Whether the default applies. An inactive row is kept but never used. |
| `notes` | Why this default was chosen. |

The pair `(app, objectType)` is the key: two apps can both have a `decision` without one's default touching the other's. OpenRegister cannot enforce that pair as unique, so keep one active row per pair.

### How the default is used

- A publication's own category always wins. The default is only read when the publication has no `wooCategory`, `category`, `tooiCategorieUri` or `tooiCategorieNaam`.
- For OpenCatalogi's own publications the row is looked up with `app: opencatalogi` and `objectType` set to the publication's schema slug.
- The row's URI is checked against the waardelijst again. A row that names no member is treated as if there were no row: the category is left out and reported, never written as free text.
- A row for a kind of object that does not exist yet does nothing until it does.

### Reading the defaults from another app

There is no separate API. Any app reads the defaults with an ordinary OpenRegister object query on the `publication` register and the `wooCategoryMapping` schema, filtered on `app` and `objectType`, the same way OpenCatalogi reads them.

The defaults shipped with OpenCatalogi are the eight decidesk rows (meeting agendas, decision lists, minutes, decisions, motions, commitments, council information letters and arrangements). Their categories are resolved to real TOOI URIs.
