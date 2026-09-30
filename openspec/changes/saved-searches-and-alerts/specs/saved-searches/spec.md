---
status: proposed
---

# Saved searches and alerts

## ADDED Requirements

### Requirement: A signed-in resident saves a search with a frequency (REQ-SSA-001)

Implements hydra `woo-citizen-journey` design C2, journey steps J6.1 and J6.2.

The `saveSearch` action SHALL create a `savedSearch` with the resident's `subjectRef` as `owner`, the given `title` and `query`, `frequency` one of `immediate`, `daily` or `weekly` (default `daily`) and `active: true`. A query that is not the contract shape, or an unknown frequency, SHALL answer 422. The resident SHALL be able to change the title and frequency, pause and delete their own saved searches. A saved search of another subject SHALL answer 404.

#### Scenario: Save a search from the search block

- **GIVEN** a signed-in resident who searched for "windpark" in information category infocat014
- **WHEN** they save it as "Windpark" without a frequency
- **THEN** a saved search "Windpark" owned by them exists with `frequency: daily` and `active: true`

#### Scenario: Pause in one click

- **GIVEN** a notice about a saved search
- **WHEN** the resident opens it and chooses pause
- **THEN** the saved search has `active: false` and the next run skips it

### Requirement: Matching finds only publications the resident could have found, new since the last run (REQ-SSA-002)

Implements hydra `woo-citizen-journey`: A saved search MUST notify only about publications the resident could have found.

The job SHALL run each due saved search through the anonymous public search path of `GET /api/search`. A match SHALL be a `publication` that is public at the moment of the run and whose `publicationDate` lies after `lastRunAt` and at or before the moment of the run. A saved search without `lastRunAt` SHALL be baselined without a notice.

#### Scenario: A publication that is not public yet

- **GIVEN** a matching publication whose publication date lies in the future
- **WHEN** the job runs
- **THEN** it is not in any notice until the day it becomes public

#### Scenario: A new saved search

- **GIVEN** a saved search created a minute ago, with ten older matching publications
- **WHEN** the job runs
- **THEN** no notice is sent and `lastRunAt` is set

### Requirement: The frequency decides when and how the resident hears (REQ-SSA-003)

Implements hydra `woo-citizen-journey`: A saved search MUST notify only about publications the resident could have found (the frequency half).

The job SHALL run every 15 minutes. `immediate` searches SHALL be handled every run with one notice per match. `daily` searches SHALL be handled once a day after 07:00, and `weekly` searches on Monday after 07:00, each with one notice listing at most 20 publications and the total count. A paused search SHALL be skipped.

#### Scenario: A daily digest

- **GIVEN** a daily saved search and three new matching publications since yesterday
- **WHEN** the job runs after 07:00
- **THEN** the saved search is saved once with the three publications in `lastMatches` and a new `lastNotifiedAt`

#### Scenario: Immediately

- **GIVEN** an immediate saved search and two new matches
- **WHEN** the job runs
- **THEN** `lastNotifiedAt` changes twice, once per publication

### Requirement: The notice reaches the resident through portaliq, and a crash sends late, not never (REQ-SSA-004)

Implements hydra `woo-citizen-journey`: Every answer, decision and alert MUST reach the resident through portaliq's notice path.

opencatalogi's manifest SHALL declare the change rule `opencatalogi.savedSearch.matched` on field `lastNotifiedAt` of `mySavedSearches`. A notice SHALL be handed over by saving the new `lastNotifiedAt`. `lastRunAt` SHALL move to the end of the window only in the save of the last notice, or alone when nothing matched. When portaliq is not installed the job SHALL do nothing and SHALL NOT fail.

#### Scenario: The save of a notice fails

- **GIVEN** a daily saved search with matches
- **WHEN** saving the notice throws
- **THEN** `lastRunAt` is unchanged and the next run finds the same matches

#### Scenario: The manifest declares the rule

- **GIVEN** a subject with audience `citizen`
- **WHEN** portaliq asks for opencatalogi's contribution
- **THEN** its notifications hold the rule `opencatalogi.savedSearch.matched` on `lastNotifiedAt`
