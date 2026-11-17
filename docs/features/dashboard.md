# Dashboard: the app shell, deep links and widgets

OpenCatalogi's internal pages are one Vue single-page app. Nextcloud serves the same
HTML template for every page of it, and the app's router picks the view from the URL, so
a link to any page can be bookmarked, shared and reloaded.

## Deep links

Every page the router knows has its own server route in `appinfo/routes.php`, answered by
`UiController`, which returns the app template:

| URL under `/apps/opencatalogi` | Page |
|---|---|
| `/` | Dashboard |
| `/catalogi` | Catalogues |
| `/publications/{catalogSlug}` | Publications of one catalogue |
| `/publications/{catalogSlug}/{id}` | One publication |
| `/search` | Search |
| `/organizations` | Organisations |
| `/themes` | Themes |
| `/glossary` | Glossary |
| `/pages` | Pages |
| `/menus` | Menus |
| `/directory` | Directory of federated catalogues |

A URL that is not in this list answers 404 from Nextcloud. `catalogSlug` and `id` accept
letters, digits and hyphens.

The template's Content Security Policy allows the page to connect to any domain
(`connect-src *`), because the Directory page and the search read other OpenCatalogi
instances straight from the browser.

## Dashboard widgets

The app registers these widgets on the Nextcloud dashboard (`lib/Dashboard/DashboardRegistrar.php`):

| Widget | Shows |
|---|---|
| Catalogi Overview | The catalogues of this instance |
| Concept publicaties | Publications that are not published yet (see below) |
| Unpublished documents | Files on a publication that are not published yet (see below) |
| Most viewed publications | The publications read most often |
| Retention review | Publications whose retention term needs a decision |

### Unpublished publications and documents

Both widgets read `GET /api/dashboard/unpublished` (`kind=publications` or
`kind=documents`, `limit` up to 50). The list holds only what the signed-in
user may read, newest first, with the total above it.

- **Concept publicaties** lists a publication that has no publication date,
  or one in the future (scheduled), and has not been withdrawn. A public or a
  withdrawn publication is not listed.
- **Unpublished documents** lists a file on a publication whose published
  time is empty or in the future, with the title of its publication.
- A row opens its publication in the app, under the catalogue it belongs to.
  A publication outside every catalogue shows, but its row opens nothing.
- With nothing to show, the widgets say "No concept publications found" and
  "No unpublished documents".
- To stay fast, both lists look at the 200 most recently changed
  publications. When there are more, the answer says so (`scope: recent-200`).

The demo data (installed on request) carries one scheduled example
publication, the council agenda of the fictional Gemeente Voorbeeld, so the
widget shows a row after installing it; its withdrawn example quarterly report
is not listed.

## What the app does on start

`Application::register()` loads the app's Composer autoload and lets each part register
its own classes: dashboard widgets, event listeners, MCP tools, observability, the portal,
services and tools. The listeners that matter for the catalogue:

| OpenRegister event | Listener | Why |
|---|---|---|
| object created / updated | `ObjectCreatedEventListener`, `ObjectUpdatedEventListener` | Automatic publishing rules |
| object created / updated / deleted | `CatalogCacheEventListener` | Keeps the catalogue cache current |
| tool registration | `ToolRegistrationListener` | Offers OpenCatalogi's tools to the AI assistant |

`boot()` does nothing on purpose: the settings are initialised by the
`InitializeSettings` repair step during install and upgrade, not on every request.

## Changes

- The route `GET /index` pointed at a controller method that no longer existed and gave a
  server error. It was removed; the URL now answers 404.

See also [directory.md](./directory.md) for the federated directory and its scheduled jobs.
