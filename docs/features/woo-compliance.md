# Woo compliance: default information categories

## The default category of a kind of object

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

## How the default is used

- A publication's own category always wins. The default is only read when the publication has no `wooCategory`, `category`, `tooiCategorieUri` or `tooiCategorieNaam`.
- For OpenCatalogi's own publications the row is looked up with `app: opencatalogi` and `objectType` set to the publication's schema slug.
- The row's URI is checked against the waardelijst again. A row that names no member is treated as if there were no row: the category is left out and reported, never written as free text.
- A row for a kind of object that does not exist yet does nothing until it does.

## Reading the defaults from another app

There is no separate API. Any app reads the defaults with an ordinary OpenRegister object query on the `publication` register and the `wooCategoryMapping` schema, filtered on `app` and `objectType`, the same way OpenCatalogi reads them.

The defaults shipped with OpenCatalogi are the eight decidesk rows (meeting agendas, decision lists, minutes, decisions, motions, commitments, council information letters and arrangements). Their categories are resolved to real TOOI URIs.
