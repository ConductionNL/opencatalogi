# Architecture

OpenCatalogi is a Nextcloud app. It turns a Nextcloud into a public catalogue of publications, software and data, and helps an organisation meet its duties under the Wet open overheid (Woo). It stores its catalogs, publications and listings as objects in OpenRegister.

This page is for new developers and for anyone who assesses the code, such as SIG. It names the functional components, says where each one starts and stops, and marks every top-level directory as production, test, generated or tooling.

## System boundary

OpenCatalogi runs inside a Nextcloud server, in PHP on the server and Vue in the browser. It has no tables of its own for catalogue data.

| Talks to | How |
|---|---|
| OpenRegister | In-process calls to `OCA\OpenRegister\...` services. OpenRegister owns the data model and storage. |
| Nextcloud server | The public `OCP` API (users, files, events, background jobs, dashboard) |
| Other OpenCatalogi instances | HTTP, for the federated directory and broadcasts |
| National indexes | HTTP. We deliver to PLOOI, and data.overheid.nl harvests our DCAT feed. |
| IntegrIQ | Optional, in-process. It reads the external case-type catalogue and runs the publiccode harvest. |

## Directory roles

| Directory | Role |
|---|---|
| `lib/` | Production PHP, PSR-4 under `OCA\OpenCatalogi`. |
| `lib/Settings/` | Register definitions (JSON), value lists copied from government sources, and the demo example set. Data, not logic. |
| `lib/Templates/` | Twig templates for PDF output. |
| `lib/Migration/` | Database migrations. Frozen once released. |
| `src/` | Production front end (Vue 3, TypeScript and JavaScript). Files ending in `.spec.js` or `.spec.ts` are tests, and `*.mock.ts` files are test data. |
| `appinfo/`, `templates/` | App manifest, route table and the PHP pages that mount the front end. |
| `tests/` | Test code only: PHPUnit (`Unit/`, `Integration/`), Playwright (`e2e/`), federation tests, Vitest helpers, stubs, mocks and fixtures. |
| `l10n/` | Translation catalogues. `<locale>.js` is generated from `<locale>.json` by `npm run l10n:build`. |
| `scripts/` | Developer tooling: translation checks, coverage guard, demo data generators. |
| `openspec/` | Specifications and change proposals (Markdown). |
| `docs/` | Documentation site. |
| `docker/`, `docker-compose*.yml`, `opencatalogi-compose.yaml` | Local development and demo environments. |

## Functional components

We group the code by what it does, not by technical layer. The class name or its directory decides the component.

| Component | What it covers | PHP lines |
|---|---|---|
| Publications and catalogs | Catalogs, publications, listings, attachments, themes, glossary, dashboard widgets | 7.3k |
| Disclosure duties | Woo requests, obligations, withheld documents, comment periods, depublication, delivery to national indexes | 5.4k |
| Open standards | DCAT, OOAPI, schema.org, publiccode, TOOI value lists, the case-type catalogue | 3.6k |
| Portal and community | The public portal, citizen collections, notice boards, feeds, sitemaps | 3.0k |
| Federation and directory | The catalogue directory, federation between instances, broadcasts | 2.8k |
| Search | Public search, saved searches, statistics | 2.4k |
| Retention | Retention policies and their evaluation | 0.8k |
| Application wiring | Bootstrap, settings, repair steps, commands, the MCP tool provider | 3.2k |
| Web interface | The Vue front end in `src/` | |

Counts exclude `lib/Migration/`, which SIG leaves out by default.

### Boundaries

Every component uses **Publications and catalogs**, because a publication is what the other components act on. Every component also uses **Application wiring** for shared helpers. Those dependencies are intended.

Decoupling between components is in progress. The aim is that the other components reach publications through a small interface.

## How a request flows

1. The browser, or a public portal visitor, calls a route from `appinfo/routes.php`.
2. A controller in `lib/Controller/` checks access and hands over to a service in `lib/Service/`.
3. The service reads and writes objects through OpenRegister.
4. Listeners in `lib/Listener/` react to OpenRegister object events, for example to refresh catalog access or deliver to a national index.
5. Background jobs in `lib/BackgroundJob/` run directory sync, delivery and retention outside the request.

## Test code

All PHP test code lives in `tests/`. Front-end unit tests sit next to the code they test as `*.spec.js` or `*.spec.ts`, with test data in `*.mock.ts`. End-to-end tests are Playwright specs in `tests/e2e/`.

## What a default scan picks up

SIG's Sigrid excludes `vendor/`, `node_modules/`, `docs/`, migrations, mocks and stubs by default. Two things it does not exclude by default:

- `l10n/*.js`: generated translation catalogues that would count as JavaScript.
- `scripts/`: developer tooling that would count as production code.

`sigrid.yaml` excludes both and pins the model version. Components are left to Sigrid's automatic detection.

## Licence

OpenCatalogi is licensed under EUPL-1.2.
