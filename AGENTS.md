# AGENTS.md

Guidance for coding agents working in this repository. For translation (l10n) work, read [CLAUDE.md](CLAUDE.md) first: it describes the l10n scripts that must be used instead of hand edits.

## What this app is

OpenCatalogi is a Nextcloud app that turns a Nextcloud into a public-facing catalogue of publications, software and data, with DCAT-AP export and Woo (Wet open overheid) support. It stores all its data as objects in [OpenRegister](https://github.com/ConductionNL/openregister) (no own database tables for catalogue data) and federates catalogs across OpenCatalogi instances.

See [README.md](README.md) and [ARCHITECTURE.md](ARCHITECTURE.md) for the functional components and directory roles.

## Setup

- `docker compose -f docker/docker-compose.yml up -d --build` builds an evaluation image with OpenCatalogi and OpenRegister enabled, served on http://localhost:8080 (see [README.md](README.md), section "Try it with Docker", and [docker/docker-compose.yml](docker/docker-compose.yml)).
- [docker-compose.yml](docker-compose.yml) at the root is a development stack (Nextcloud on PostgreSQL by default, `--profile mariadb` for MariaDB) that mounts `./custom-apps` as `custom_apps`.
- Install dependencies with `composer install` and `npm install`. Both set `git config core.hooksPath .githooks`.

## Build

```bash
npm run build      # production webpack build
npm run dev        # one-time development build
npm run watch      # development build with rebuild on change
npm run l10n:build # regenerate l10n/*.js from l10n/*.json
```

## Test

```bash
composer test:unit   # PHPUnit (phpunit.xml)
composer test:all    # PHPUnit; skips itself when no Nextcloud server tree is found at ../../lib/base.php
npm test             # Jest, unit specs under src/ (jest.config.js)
npm run test:unit    # Vitest, specs under tests/vitest/ (vitest.config.js)
npm run test:e2e     # Playwright, specs under tests/e2e/ (playwright.config.ts)
```

The PHPUnit suite needs a Nextcloud server tree, so run it from inside a Nextcloud checkout or rely on the CI PHPUnit job. Playwright needs a browser: `npm run test:e2e:install`.

## Lint and quality

```bash
composer check:strict  # lint, phpcs, phpmd, psalm, phpstan and test:all in one run
composer lint          # php -l over all PHP files
composer phpcs         # coding standard (phpcs.xml)
composer phpmd         # mess detection (phpmd.xml, phpmd.baseline.xml)
composer psalm         # static analysis (psalm.xml)
composer phpstan       # static analysis (phpstan.neon)
npm run lint           # ESLint over src, tests and scripts
npm run stylelint      # CSS and SCSS linting
npm run format         # Prettier check
npm run check:manifest # validate src/manifest.json against the app manifest schema
npm run check:l10n     # translation audit (see CLAUDE.md)
npm run check:l10n-js  # l10n/*.js matches what l10n/*.json generates
```

CI runs the shared quality workflow with this app's settings from [.github/workflows/code-quality.yml](.github/workflows/code-quality.yml).

## OpenSpec flow

Specifications live in [openspec/](openspec/) (configuration in [openspec/config.yaml](openspec/config.yaml)).

1. Start a change in `openspec/changes/<name>/` with `proposal.md`, `tasks.md`, optionally `design.md`, and delta specs in `specs/<capability>/spec.md`.
2. The accepted requirements live in `openspec/specs/<capability>/spec.md`.
3. Finished changes move to `openspec/changes/archive/` with a date prefix (for example `openspec/changes/archive/2026-02-17-org-archimate-export/`).

## Architecture decisions

App-level architecture decisions are kept as numbered records in [openspec/architecture/](openspec/architecture/) (for example [ADR-001](openspec/architecture/adr-001-information-architecture.md) on the information architecture). Decisions for a single change are recorded in that change's `design.md` under `openspec/changes/`.

## Repo conventions

- Code is grouped by what it does, not by technical layer ([ARCHITECTURE.md](ARCHITECTURE.md), "Functional components").
- The data model is configuration: registers and schemas are JSON definitions in `lib/Settings/` (for example `lib/Settings/publication_register.json`), and data is read and written through OpenRegister.
- Pages and menus are manifest configuration: [src/manifest.json](src/manifest.json) declares them, and a change can add a fragment in [src/manifest.d/](src/manifest.d/README.md) instead of editing the base manifest.
- User-visible frontend strings are wrapped in `t('opencatalogi', '...')`; catalogues are edited as `l10n/*.json` and regenerated (see [CLAUDE.md](CLAUDE.md)).

## Commit format

Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`), present tense, imperative mood, first line at most 72 characters. See [CONTRIBUTING.md](CONTRIBUTING.md), section "Git Commit Messages".
