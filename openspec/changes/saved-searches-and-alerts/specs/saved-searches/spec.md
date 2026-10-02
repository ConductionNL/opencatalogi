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

For each notice opencatalogi SHALL write a `portalMessage` into portaliq's register for the search's owner, with the rule key `opencatalogi.savedSearch.matched` and a `recordLink` to the saved search in `mySavedSearches`. The subject SHALL name the search, and the publication when the notice holds one. The subject and body SHALL be in Dutch only. The body SHALL list each publication with its link, and say how many more there are past twenty. opencatalogi's manifest SHALL declare the rule key as a plain key, and SHALL NOT declare a change rule on the saved search, so saving the search's own bookkeeping never sends a notice. `lastRunAt` SHALL move to the end of the window only in the save after the last message, or alone when nothing matched. When portaliq is not installed the job SHALL do nothing and SHALL NOT fail.

#### Scenario: One message per new publication for an immediate search

- **GIVEN** an immediate saved search "Windpark" of subject `subject-1` and two new matching publications
- **WHEN** the job runs
- **THEN** two messages are written for `subject-1`, each with rule key `opencatalogi.savedSearch.matched`
- **AND** the first has subject `Nieuwe publicatie voor uw zoekopdracht "Windpark": Publicatie p1` and a link to the saved search

#### Scenario: A digest is one message

- **GIVEN** a weekly saved search with 25 new matches
- **WHEN** the job runs on Monday after 07:00
- **THEN** one message is written with subject `25 nieuwe publicaties voor uw zoekopdracht "Windpark"`, twenty publication lines and the line `En nog 5. Zoek opnieuw om ze allemaal te zien.`

#### Scenario: The message cannot be written

- **GIVEN** an immediate saved search with a new match
- **WHEN** writing the message throws
- **THEN** the saved search is not saved, `lastRunAt` is unchanged, and the next run writes the message

#### Scenario: The save of a notice fails

- **GIVEN** a daily saved search with matches
- **WHEN** saving the notice throws
- **THEN** `lastRunAt` is unchanged and the next run finds the same matches

#### Scenario: The manifest declares the rule key, not a change rule

- **GIVEN** a subject with audience `citizen`
- **WHEN** portaliq asks for opencatalogi's contribution
- **THEN** its notifications are exactly `["opencatalogi.savedSearch.matched"]`
