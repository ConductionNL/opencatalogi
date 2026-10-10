# publiccode-github-harvest Specification Delta

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- [publiccode-github-harvest](../../)

## Purpose

Every `publiccode.yml` on GitHub becomes a searchable component in OpenCatalogi, on a schedule, through integriq.

## ADDED Requirements

### Requirement: REQ-PGH-001 The harvest flow ships with the app and stays inert until adopted

OpenCatalogi SHALL declare one flow named "GitHub publiccode harvest" on the `publiccode` schema under `x-openregister-flows`. It SHALL ship disabled. It SHALL carry a schedule trigger and a manual trigger, and each trigger SHALL have an edge to every shard node. No edge SHALL run from one shard node to another. Every shard SHALL lead into its own tail that fetches, decodes, maps and writes. Only the end node MAY be shared, because a place several steps write into keeps only the last step's items.

#### Scenario: The shipped flow has the parallel shape
@e2e exclude A register fragment with no browser surface; tests/Unit/Settings/PubliccodeHarvestFlowTest.php asserts the topology.

- **GIVEN** the register fragment `lib/Settings/register.d/publiccode-github-harvest.json`
- **WHEN** its flow is read
- **THEN** both trigger nodes have an edge to each of the 24 shard nodes
- **AND** no shard node has an edge to another shard node
- **AND** the flow's `enabled` is false

#### Scenario: Importing the app does not start a harvest
@e2e exclude Checked live on :8095 by reading the flow store after an import; there is no page to drive.

- **GIVEN** a fresh install with integriq present
- **WHEN** OpenCatalogi's configuration is imported
- **THEN** the flow exists, disabled and without an owner
- **AND** no run of it exists

### Requirement: REQ-PGH-002 Shards partition the file sizes so no search passes 1,000 results

The shard definitions SHALL cover `size:0..393216` in contiguous ranges that neither overlap nor leave a gap. Each shard SHALL run one GitHub code search for `filename:publiccode.yml` within its range, 100 results a page, at most 10 pages, fetched one page at a time.

#### Scenario: The shard file and the flow agree
@e2e exclude Static data; tests/Unit/Settings/PubliccodeHarvestFlowTest.php.

- **GIVEN** `lib/Settings/publiccode-github-shards.json` and the shipped flow
- **WHEN** both are read
- **THEN** every shard node names a synchronization slug from the shard file, and every shard in the file has a node
- **AND** the ranges start at 0, end at 393216 and each starts one byte after the previous one ends

### Requirement: REQ-PGH-003 Each harvested file becomes one publiccode object, and a re-harvest updates it

For every hit the flow SHALL fetch the file from `github-raw`, decode it as YAML, map it onto `publiccode` with mapping `publiccode-github-hit` and upsert it by slug. The object SHALL keep the decoded document in `rawPubliccode`, carry `harvestedFrom: "github.com"` and the time of the write in `harvestedAt`. The slug SHALL derive from the `url` the file declares (scheme, a leading `www.`, a `.git` suffix, a `#fragment`, a `?query` and a trailing slash removed), plus the directory when the file is a second publiccode.yml below the root of that same repository. The repository the file was found in SHALL NOT be the identity: a fork or a copy of a file maps onto the original's slug. A field the file does not declare SHALL be absent, never the text of its own path. A `releaseDate` SHALL be taken only when it is a date (`YYYY-MM-DD…`, or the timestamp YAML makes of an unquoted date), cut to the day; anything else is dropped.

#### Scenario: A real publiccode.yml maps onto the schema
@e2e exclude Mapping fidelity has no browser surface; tests/Unit/Settings/PubliccodeMappingTest.php runs the real mapping and validates against the real schema.

- **GIVEN** this repository's own `publiccode.yml`, decoded
- **WHEN** it runs through mapping `publiccode-github-hit` as a hit from `ConductionNL/opencatalogi`
- **THEN** the result validates against the `publiccode` schema properties
- **AND** `slug` is `github-com-conductionnl-opencatalogi`, `name` is `OpenCatalogi` and `legalLicense` is `EUPL-1.2`
- **AND** `applicationSuite`, which the file does not declare, is absent

#### Scenario: A second harvest updates instead of duplicating
@e2e exclude Verified live on :8095 against the GitHub mock by counting objects after two runs.

- **GIVEN** a harvest that wrote a component for `ConductionNL/opencatalogi`
- **WHEN** the harvest runs again and finds the same file
- **THEN** the schema still holds one object with that slug
- **AND** its `harvestedAt` moved forward

#### Scenario: A copy of the file in another repository is not a second component
@e2e exclude Mapping identity; tests/Unit/Settings/PubliccodeMappingTest.php maps the same file as a hit from another repository. Measured on the first real crawl: 253 of the first 1,000 objects were such copies.

- **GIVEN** `ConductionNL/opencatalogi`'s publiccode.yml, copied unchanged into `someone-else/hack-day-fork`
- **WHEN** the hit from the fork runs through the mapping
- **THEN** its `slug` is `github-com-conductionnl-opencatalogi`, the same as the original's
- **AND** the flow's filter drops the hit before the write, because the file's `url` names another GitHub repository than the one it was found in

#### Scenario: A releaseDate that is not a date does not fail the run
@e2e exclude One template repository on GitHub ships `releaseDate: ${RELEASE_DATE}`; tests/Unit/Settings/PubliccodeMappingTest.php covers it.

- **GIVEN** a publiccode.yml whose `releaseDate` is `${RELEASE_DATE}`
- **WHEN** it runs through the mapping
- **THEN** the component has no `releaseDate`
- **AND** the mapping does not throw, so the other hits of the page are still written

### Requirement: REQ-PGH-004 A file that cannot be read is skipped, not written

A hit whose file does not decode, or decodes without `name` and `url`, SHALL be dropped before the mapping. A hit whose path is not a file named `publiccode.yml` SHALL be dropped too: code search for `filename:publiccode.yml` also returns files such as `publiccodeyml__publiccode.yml.json`. A hit whose `url` is not text that starts with `http` and contains a dot, or whose `name` is not text, SHALL be dropped before the mapping. The mapping SHALL shape every value for its column: a scalar column takes only a scalar, a list of strings keeps only its scalar items, `description` keeps only language-keyed sub-documents, `landingURL` only a web address and `releaseDate` only a calendar date; anything else is dropped, never written. A write that the schema still refuses SHALL end the write step of its own shard only (OpenRegister writes a shard's page in one step and stops at the first refused item, so the rest of that page is not written in that run); the mapping step and the other shards SHALL continue, and the admin section SHALL show how many steps of the last run failed.

#### Scenario: A broken YAML file in the middle of a page
@e2e exclude Engine behaviour; verified live with a mock hit that points at a non-YAML file.

- **GIVEN** a page of hits where one file is not valid YAML
- **WHEN** the tail runs
- **THEN** every other hit is written
- **AND** no object is written for the broken file

#### Scenario: A url that is a list is dropped before the mapping
@e2e exclude Filter behaviour; tests/Unit/Settings/PubliccodeHarvestFlowTest.php runs the shard filter through JSON Logic.

- **GIVEN** a decoded file whose `url` is a YAML list
- **WHEN** the shard filter runs
- **THEN** the hit is dropped
- **AND** the mapping step never sees it, so the run reaches `end`

#### Scenario: An original whose url ends in .git or carries a fragment is kept
@e2e exclude Filter behaviour; tests/Unit/Settings/PubliccodeHarvestFlowTest.php runs the shard filter through JSON Logic.

- **GIVEN** a file in `ConductionNL/opencatalogi` whose `url` is `https://github.com/ConductionNL/opencatalogi.git` or `…/opencatalogi#readme`
- **WHEN** the shard filter runs
- **THEN** the hit is kept
- **AND** it maps onto slug `github-com-conductionnl-opencatalogi`

#### Scenario: A value the column would refuse is dropped, not written
@e2e exclude Mapping fidelity; tests/Unit/Settings/PubliccodeMappingTest.php runs the real mapping.

- **GIVEN** a file with `releaseDate: 2026-13-45`, `landingURL: not a url`, `logo` as a list, a `categories` list with one map in it and `description` as a list
- **WHEN** it runs through mapping `publiccode-github-hit`
- **THEN** `releaseDate`, `landingURL`, `logo` and `description` are absent
- **AND** `categories` keeps only its string items

#### Scenario: A file that is not a publiccode.yml is dropped before the write
@e2e exclude Found on the first real crawl: `xuhongxu96/bazel-repos search_repos/repos/publiccodeyml__publiccode.yml.json`, whose string `description` failed the json column and stopped the run after 13 of 24 shards. tests/Unit/Settings/PubliccodeHarvestFlowTest.php asserts the filter and the write policy.

- **GIVEN** a hit whose path ends in `publiccode.yml.json`
- **WHEN** the tail runs
- **THEN** the filter drops the hit
- **AND** had a write failed anyway, only that shard ends; the other 23 shards complete

### Requirement: REQ-PGH-005 A rate-limited search pauses the run and never repeats finished shards

When GitHub refuses a search for its rate limit, the run SHALL suspend until the limit resets and then continue with the refused shard. Shards that already finished SHALL NOT run again in that run.

#### Scenario: The limit runs out halfway through
@e2e exclude Engine behaviour (integriq suspends, OpenRegister resumes); verified live against the mock answering 403 with `X-RateLimit-Remaining: 0`.

- **GIVEN** a run where the third search answers 403 with `X-RateLimit-Remaining: 0`
- **WHEN** the run resumes after `X-RateLimit-Reset`
- **THEN** the refused shard runs again
- **AND** the shards that finished before the refusal do not

### Requirement: REQ-PGH-006 The administrator sets the harvest up, switches it on and runs it from OpenCatalogi

The OpenCatalogi admin settings SHALL show a "GitHub harvest" section. It SHALL show whether integriq is installed, whether its `github-api` source exists and is enabled, how many shard definitions exist, whether the flow is on, which account it runs as and the result of the last run. It SHALL offer set up, switch on, switch off and run now, and a link to the source in integriq for the token. Without integriq it SHALL say integriq is needed and offer no action. OpenCatalogi SHALL NOT read, store or send the token. Every endpoint behind the section SHALL be admin only.

#### Scenario: Without integriq nothing can be started
- **GIVEN** an instance without integriq
- **WHEN** an administrator opens the GitHub harvest section
- **THEN** it says the harvest needs integriq
- **AND** it shows no set up, switch on or run button

#### Scenario: Set up, switch on and run
- **GIVEN** an instance with integriq and its `github-api` source enabled
- **WHEN** the administrator chooses Set up, then Switch on, then Run now
- **THEN** the section shows 24 of 24 shards, the harvest as on, and a last run that has started

#### Scenario: Set up twice
@e2e exclude Idempotency of a server write; tests/Unit/Service/PubliccodeHarvestServiceTest.php.

- **GIVEN** a harvest that was set up before
- **WHEN** the administrator chooses Set up again
- **THEN** each shard synchronization is updated in place and none is added

### Requirement: REQ-PGH-007 Harvested components are found in search and in the public API

OpenCatalogi SHALL seed a catalogue `componenten` over the `publiccode` schema, listed and published. The `publiccode` schema SHALL be readable by the public group. A harvested component SHALL be found through OpenCatalogi's search and through the public API without signing in and without further configuration.

#### Scenario: An anonymous visitor finds a harvested component
- **GIVEN** a harvested component named "OpenCatalogi"
- **WHEN** an anonymous visitor searches the Componenten catalogue for "OpenCatalogi"
- **THEN** the component is in the results

### Requirement: REQ-PGH-008 The manual describes the harvest that ships

`docs/handleidingen/Publiccode.md` SHALL describe how this harvest finds files, what an administrator sets up, how a maintainer gets a component found, and the limits (the default branch only, files under 384 KB, 1,000 results per shard). It SHALL NOT claim a nightly scan when none is switched on.

#### Scenario: A maintainer reads how to get listed
@e2e exclude Documentation content.

- **GIVEN** the manual
- **WHEN** a maintainer reads it
- **THEN** it tells them to put `publiccode.yml` on the default branch and that the next nightly harvest picks it up once their catalogue runs it
