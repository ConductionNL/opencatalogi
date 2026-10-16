# publiccode-developer-overheid-harvest Specification Delta

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- [publiccode-developer-overheid-harvest](../../)

## Purpose

Every repository in the developer.overheid.nl OSS-register that has a `publiccode.yml` becomes a searchable component in OpenCatalogi, read from the forge it lives on, through integriq, and shared with the GitHub harvest as one component per repository. The flow and mappings are declared in the register fragment (ADR-031); the administrator drives the harvest from OpenCatalogi's admin settings and never hands OpenCatalogi the API key (ADR-064).

## ADDED Requirements

### Requirement: REQ-PDH-001 The harvest flow ships with the app and stays inert until adopted

OpenCatalogi SHALL declare one flow named "developer.overheid.nl publiccode harvest" on the `publiccode` schema under `x-openregister-flows`. It SHALL ship disabled and without an owner. It SHALL carry a schedule trigger and a manual trigger, and both SHALL lead to one list step. After the list step and the per-repository explode and locate steps, the flow SHALL fan out into three branches (github.com, gitlab.com, any other host), each with its own fetch, filter, map and write steps, and no edge SHALL run from one branch into another. Only the end node MAY have more than one incoming edge, and it SHALL wait for every branch (`join: true`). Importing the app SHALL NOT start a run.

#### Scenario: The shipped flow has the three-branch shape
@e2e exclude A register fragment with no browser surface; tests/Unit/Settings/DeveloperOverheidHarvestFlowTest.php asserts the topology.

- **GIVEN** the register fragment `lib/Settings/register.d/publiccode-developer-overheid-harvest.json`
- **WHEN** its flow is read
- **THEN** both trigger nodes have an edge to the list node and to nothing else
- **AND** the locate node has exactly three outgoing edges, one to each forge filter
- **AND** only the end node has more than one incoming edge, and it joins
- **AND** the flow's `enabled` is false

#### Scenario: Importing the app does not start a harvest
@e2e exclude Checked live by reading the flow store after an import; there is no page to drive.

- **GIVEN** a fresh install with integriq present
- **WHEN** OpenCatalogi's configuration is imported
- **THEN** the flow exists, disabled and without an owner
- **AND** no run of it exists
- **AND** no request is sent to the OSS-register

### Requirement: REQ-PDH-002 The register is read page by page through one synchronization

The list step SHALL fetch `GET /repositories` on the integriq source `developer-overheid-oss` with `publiccode=true` and `perPage=100`, following pages until the register has no more, through the synchronization `opencatalogi-publiccode-developer-overheid`. That synchronization SHALL NOT be seeded; OpenCatalogi SHALL write it when an administrator sets the harvest up, from `lib/Settings/publiccode-developer-overheid-sync.json`. A listing longer than the synchronization's page limit SHALL raise rather than be cut short. Each repository of each page SHALL become one item.

#### Scenario: One page of 92 repositories
@e2e exclude Engine behaviour; verified live against integriq's mock of the register.

- **GIVEN** a register that answers 92 repositories on page 1 with `Total-Pages: 1`
- **WHEN** the harvest runs
- **THEN** the list step sends one request with `publiccode=true&perPage=100&page=1`
- **AND** 92 items reach the locate step

#### Scenario: Two pages
@e2e exclude Engine behaviour; verified live against the mock with 150 repositories.

- **GIVEN** a register of 150 repositories with a `Link` header naming page 2 as last
- **WHEN** the harvest runs
- **THEN** pages 1 and 2 are fetched and 150 items reach the locate step

#### Scenario: The synchronization is written on set-up, not on import
@e2e exclude Server write; tests/Unit/Service/PubliccodeHarvestServiceTest.php.

- **GIVEN** a fresh install with integriq
- **WHEN** OpenCatalogi's configuration is imported
- **THEN** no synchronization `opencatalogi-publiccode-developer-overheid` exists
- **AND** after the administrator chooses Set up, it exists with source `developer-overheid-oss`, endpoint `/repositories` and results at the root of the response

### Requirement: REQ-PDH-003 Each repository is fetched from its own forge

The locate step SHALL classify each repository by its `publicCodeUrl` and SHALL rewrite that URL into a path on an integriq source, never fetch it as an absolute URL. A github.com `blob` or `raw` URL, or a raw.githubusercontent.com URL, SHALL be fetched from `github-raw` at `/{owner}/{repo}/{ref}/{path}`. A gitlab.com `/-/blob/` or `/-/raw/` URL SHALL be fetched from `gitlab-raw` at `/{namespace}/{project}/-/raw/{ref}/{path}`. A query string or fragment on the URL SHALL be ignored. The fetched body SHALL be decoded as YAML.

#### Scenario: A github.com blob URL
@e2e exclude Mapping behaviour; tests/Unit/Settings/DeveloperOverheidMappingTest.php runs the locate mapping.

- **GIVEN** a repository with `publicCodeUrl` `https://github.com/ConductionNL/opencatalogi/blob/main/publiccode.yml`
- **WHEN** the locate step runs
- **THEN** the item's forge is `github` and its raw path is `/ConductionNL/opencatalogi/main/publiccode.yml`

#### Scenario: A gitlab.com blob URL with a query string
@e2e exclude Mapping behaviour; tests/Unit/Settings/DeveloperOverheidMappingTest.php.

- **GIVEN** a repository with `publicCodeUrl` `https://gitlab.com/example-group/sub/project/-/blob/main/publiccode.yml?ref_type=heads`
- **WHEN** the locate step runs
- **THEN** the item's forge is `gitlab` and its raw path is `/example-group/sub/project/-/raw/main/publiccode.yml`

#### Scenario: A file below the repository root
@e2e exclude Mapping behaviour; tests/Unit/Settings/DeveloperOverheidMappingTest.php.

- **GIVEN** a repository with `publicCodeUrl` `https://github.com/example-org/monorepo/blob/main/apps/portal/publiccode.yml`
- **WHEN** the locate step runs
- **THEN** the raw path is `/example-org/monorepo/main/apps/portal/publiccode.yml`
- **AND** the file path is `apps/portal/publiccode.yml`

### Requirement: REQ-PDH-004 A repository on another forge falls back to the register's parsed publiccode

A repository whose `publicCodeUrl` is on a host without a raw-file source, has no file path, or is empty SHALL be read from `GET /repositories/{id}` on `developer-overheid-oss`, and its `publicCode` SHALL be mapped the same way a fetched file is. The fallback SHALL NOT write `rawPubliccode`, because the register's parse is not the file. A failed detail call SHALL skip that repository only.

#### Scenario: A Codeberg repository
@e2e exclude Engine behaviour; verified live against the mock's detail endpoint; the mapping half in tests/Unit/Settings/DeveloperOverheidMappingTest.php.

- **GIVEN** a repository with `publicCodeUrl` `https://codeberg.org/example-org/app/src/branch/main/publiccode.yml` and id `00000000-0000-0000-0000-000000000000`
- **WHEN** the harvest runs
- **THEN** the flow calls `/repositories/00000000-0000-0000-0000-000000000000` on `developer-overheid-oss`
- **AND** a component is written with `harvestedFrom` `codeberg.org` and without `rawPubliccode`

#### Scenario: The fallback does not erase what the GitHub harvest wrote
@e2e exclude Patch semantics; verified live by running both harvests over one repository.

- **GIVEN** a component the GitHub harvest wrote with `categories` and `softwareVersion`
- **WHEN** the fallback writes the same slug from a register parse that lacks both fields
- **THEN** the component still has its `categories` and `softwareVersion`

### Requirement: REQ-PDH-005 One object per repository, shared with the GitHub harvest

Each repository SHALL be written with an upsert on `@self.slug`. The slug SHALL be derived from the `url` the file declares exactly as mapping `publiccode-github-hit` derives it (lower case; scheme, a leading `www.`, a `.git` suffix, a `#fragment`, a `?query` and a trailing slash removed; every other character outside `a-z0-9` replaced by `-`), plus the file's directory when the file is below the root of the repository its `url` names. The write SHALL patch: a field the write does not name SHALL keep its value. The object SHALL carry `harvestedFrom` the host the file came from (`github.com`, `gitlab.com`, or the other host for the fallback), `harvestedAt` the time of the write, and `developerOverheid` with the register's `id`, `organisation` (`label`, `uri`), `registerUrl` (`https://oss.developer.overheid.nl/repositories/{id}`), `lastCrawledAt`, `forkType` and `archived`. A second run SHALL update the same objects and add none.

#### Scenario: A repository both harvests find is one component
@e2e exclude Mapping identity; tests/Unit/Settings/DeveloperOverheidMappingTest.php maps the same file through both mappings.

- **GIVEN** this repository's own `publiccode.yml`, found by the GitHub harvest in `ConductionNL/opencatalogi` and listed in the register with `publicCodeUrl` `https://github.com/ConductionNL/opencatalogi/blob/main/publiccode.yml`
- **WHEN** it runs through mapping `publiccode-developer-overheid-repository`
- **THEN** its `slug` is `github-com-conductionnl-opencatalogi`, the slug `publiccode-github-hit` gives
- **AND** `harvestedFrom` is `github.com` and `developerOverheid.registerUrl` ends in the register id

#### Scenario: The GitHub harvest keeps the register listing
@e2e exclude Patch semantics; verified live by running the GitHub harvest after this one.

- **GIVEN** a component written by this harvest with a `developerOverheid` block
- **WHEN** the GitHub harvest writes the same slug
- **THEN** `developerOverheid` is unchanged

#### Scenario: A second run updates instead of duplicating
@e2e exclude Verified live against the mock by counting objects after two runs.

- **GIVEN** a run that wrote 92 components
- **WHEN** the harvest runs again over the same register
- **THEN** the schema still holds the same 92 objects from this harvest
- **AND** their `harvestedAt` moved forward

### Requirement: REQ-PDH-006 A file that cannot be read is skipped, not written

A repository whose file answers 404, does not decode as YAML, decodes without `name` and `url`, has a `url` that is not text starting with `http` and containing a dot, or a `name` that is not text SHALL be dropped before the mapping. A repository whose file `url` names a different repository than the register's own `url` on the same host SHALL be dropped as a copy. A dropped or failed repository SHALL NOT end the run: every other repository SHALL still be fetched, mapped and written, and the admin section SHALL show how many steps of the last run failed.

#### Scenario: A 404 and a broken YAML file in one page
@e2e exclude Engine behaviour; verified live with mock records that point at a missing file and at a non-YAML file.

- **GIVEN** a page of ten repositories where one file answers 404 and one is not valid YAML
- **WHEN** the harvest runs
- **THEN** eight components are written
- **AND** the run reaches the end node

#### Scenario: A fork whose file names the original is dropped
@e2e exclude Filter behaviour; tests/Unit/Settings/DeveloperOverheidHarvestFlowTest.php runs the filter through JSON Logic.

- **GIVEN** a register repository `https://github.com/example-fork/opencatalogi` whose file declares `url: https://github.com/ConductionNL/opencatalogi`
- **WHEN** the branch filter runs
- **THEN** the item is dropped and nothing is written for it

#### Scenario: An original whose url ends in .git is kept
@e2e exclude Filter behaviour; tests/Unit/Settings/DeveloperOverheidHarvestFlowTest.php.

- **GIVEN** a register repository `https://github.com/ConductionNL/opencatalogi` whose file declares `url: https://github.com/ConductionNL/opencatalogi.git`
- **WHEN** the branch filter runs
- **THEN** the item is kept

### Requirement: REQ-PDH-007 The publiccode schema records the register listing additively

Schema `publiccode` SHALL go from version 0.3.0 to 0.4.0 by adding one optional property: `developerOverheid` (object: `id`, `organisation` with `label` and `uri`, `registerUrl`, `lastCrawledAt`, `forkType`, `archived`). Like the GitHub harvest, which adds no source-specific field, the harvest SHALL NOT add a separate flag; a component is listed in the register when `developerOverheid` is present. No property SHALL be removed, renamed or made stricter, and `required` SHALL stay `name` and `url`. The demo data in the mock register SHALL show both a listed and an unlisted component.

#### Scenario: A 0.3.0 object stays valid
@e2e exclude Schema compatibility; tests/Unit/Settings/DeveloperOverheidMappingTest.php validates a 0.3.0-shaped object against the 0.4.0 properties.

- **GIVEN** a component written before this change, without the new fields
- **WHEN** it is validated against schema 0.4.0
- **THEN** it is valid

#### Scenario: A visitor sees where a component is registered
- **GIVEN** a component listed in the register
- **WHEN** an anonymous visitor reads it through `/api/componenten/{id}`
- **THEN** the response carries `developerOverheid.registerUrl` and `developerOverheid.organisation.label`

### Requirement: REQ-PDH-008 The administrator sets the harvest up, switches it on and runs it from OpenCatalogi

The OpenCatalogi admin settings SHALL show a section "developer.overheid.nl harvest" next to "GitHub harvest". It SHALL show whether integriq is installed, whether the source `developer-overheid-oss` exists and is enabled (checked the same way the GitHub section checks `github-api`), whether `github-raw` and `gitlab-raw` exist, whether the synchronization exists, whether the flow is on, which account it runs as and the result of the last run with its number of failed steps. It SHALL offer Set up, Switch on, Switch off and Run now, and a link to the source in integriq. Without integriq it SHALL say integriq is needed and offer no action. Every endpoint behind it SHALL be admin only, SHALL answer a refused action with a static localised `message` and an `error` slug, and SHALL NOT return exception text. The GitHub harvest section and its endpoints SHALL behave as before.

#### Scenario: Without integriq nothing can be started
- **GIVEN** an instance without integriq
- **WHEN** an administrator opens the developer.overheid.nl harvest section
- **THEN** it says the harvest needs integriq
- **AND** it shows no Set up, Switch on or Run now button

#### Scenario: The source is switched off
- **GIVEN** an instance with integriq and the source `developer-overheid-oss`, switched off
- **WHEN** an administrator opens the section
- **THEN** it says the source is switched off and that the key is entered on it
- **AND** it links to the source in integriq and to where a key is requested

#### Scenario: Set up, switch on and run
- **GIVEN** an instance with integriq, the credential and the enabled source `developer-overheid-oss`
- **WHEN** the administrator chooses Set up, then Switch on, then Run now
- **THEN** the section shows the synchronization present, the harvest on, and a last run that has started

#### Scenario: Running before switching on is refused
@e2e exclude Controller behaviour; tests/Unit/Controller/DeveloperOverheidHarvestControllerTest.php.

- **GIVEN** a harvest that is set up and switched off
- **WHEN** `POST /api/settings/developer-overheid-harvest/run` is called by an administrator
- **THEN** the answer is 409 with `error` `switched-off` and a localised `message`
- **AND** the body contains no exception text

#### Scenario: A non-admin cannot reach the endpoints
@e2e exclude Route authorization; covered by the route-auth gate and tests/Unit/Controller/DeveloperOverheidHarvestControllerTest.php.

- **GIVEN** a signed-in user who is not an administrator and has no delegated OpenCatalogi settings
- **WHEN** they call `GET /api/settings/developer-overheid-harvest`
- **THEN** the answer is 403

#### Scenario: The GitHub section is unchanged
@e2e exclude Regression; tests/Unit/Service/PubliccodeHarvestServiceTest.php keeps the GitHub status payload.

- **GIVEN** the GitHub harvest set up before this change
- **WHEN** an administrator opens the GitHub harvest section
- **THEN** it shows the same checks, counts and actions as before

### Requirement: REQ-PDH-009 The API key never touches OpenCatalogi

OpenCatalogi SHALL NOT read, store, send, log or return the OSS-register API key. The key SHALL live in OpenRegister's credential broker as a `generic-apikey` credential and SHALL be added to the request by integriq. OpenCatalogi SHALL NOT look up the credential at all; like the GitHub harvest it SHALL learn only whether the source exists and is enabled.

#### Scenario: The status carries no secret
@e2e exclude Response content; tests/Unit/Service/PubliccodeHarvestServiceTest.php.

- **GIVEN** a credential `developer-overheid-oss` whose secret is `YOUR_API_KEY_HERE`
- **WHEN** an administrator reads the harvest status
- **THEN** the response does not contain `YOUR_API_KEY_HERE`
- **AND** the response has no credential block

### Requirement: REQ-PDH-010 Harvested components are found in search and in the public API

A component this harvest writes SHALL be found through OpenCatalogi's search and through the public API without signing in, in the existing Componenten catalogue, with no configuration beyond the harvest's own.

#### Scenario: An anonymous visitor finds a component from the register
- **GIVEN** a harvested component named "OpenCatalogi" listed in the register
- **WHEN** an anonymous visitor searches the Componenten catalogue for "OpenCatalogi"
- **THEN** the component is in the results

#### Scenario: The public API returns it
@e2e exclude API surface; verified with an anonymous request to /api/search and /api/componenten after a live run.

- **GIVEN** the same component
- **WHEN** an anonymous client calls the public search API
- **THEN** the component is returned with its `developerOverheid` block

### Requirement: REQ-PDH-011 The manual describes the second source and the key

`docs/handleidingen/Publiccode.md` SHALL describe this harvest next to the GitHub one: where it reads, that a component both find is one component, what an administrator sets up, and that the administrator requests their own read-only API key at https://apis.developer.overheid.nl/apis/key-aanvragen and stores it as a `generic-apikey` credential named `developer-overheid-oss`. It SHALL NOT contain a key or a realistic-looking example of one.

#### Scenario: An administrator reads how to get a key
@e2e exclude Documentation content.

- **GIVEN** the manual
- **WHEN** an administrator reads the developer.overheid.nl section
- **THEN** it links to https://apis.developer.overheid.nl/apis/key-aanvragen and names the credential `developer-overheid-oss` and its provider `generic-apikey`

## Non-Functional Requirements

- **Performance:** A run over 100 repositories SHALL make at most 2 list requests and one file or detail request per repository, and SHALL finish within the flow's 60-minute runtime limit.
- **Accessibility:** WCAG 2.2 AA for the admin section. Touched: 1.3.1 Info and Relationships (status list as a real list), 1.4.1 Use of Color (every status icon has a text label), 2.1.1 Keyboard (actions are buttons), 4.1.3 Status Messages (errors in an alert note card), 2.4.6 Headings and Labels (section title). New in 2.2: 2.4.11 Focus Not Obscured — n/a beyond Nextcloud's settings page, which has no sticky overlay; 2.5.7 Dragging Movements — n/a, no dragging; 2.5.8 Target Size — buttons are Nextcloud's `NcButton` (44 px); 3.2.6 Consistent Help — the help link sits in the same place as in the GitHub section; 3.3.7 Redundant Entry — n/a, no form input; 3.3.8 Accessible Authentication — n/a, no login in the section.
- **Internationalization:** Dutch and English MUST be supported for every new string (ADR-007).

## Acceptance Criteria

- The flow imports disabled and passes OpenRegister's preflight.
- A run against integriq's mock of the register writes one component per repository; a second run updates them.
- A 404 and a broken file are skipped; the run completes.
- A repository both harvests find is one object carrying both the GitHub fields and `developerOverheid`.
- Components appear anonymously in search and the public API.
- The key appears nowhere in OpenCatalogi's code, data, logs or responses.

## Notes

- Depends on integriq `sources-developer-overheid` (sources `developer-overheid-oss`, `gitlab-raw`).
- Shares the slug rule with `publiccode-github-harvest`; change both together.
