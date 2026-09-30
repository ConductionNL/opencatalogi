---
kind: code
depends_on: [citizen-collections, woo-dossier-publication]
---

# Proposal: saved-searches-and-alerts

## Why

A resident who follows a subject, say a wind farm or the council's decisions on housing, has to search again every week to see what is new. The Woo citizen journey (hydra `openspec/changes/woo-citizen-journey`, contracts C2 and C3, journey J6) lets them save a search and hear about new publications that match it.

Ruben decided on 30 September 2026: alerts reach the portal inbox, email and Berichtenbox, daily by default, with immediate and weekly per saved search.

## What changes

- A new schema `savedSearch` in the `publication` register, shaped as contract C2: `title`, `owner`, `query`, `frequency`, `active`, `lastRunAt`, `lastNotifiedAt`. The job also writes `lastMatches` and `matchCount`, so the notice has something to open.
- Portal actions for `citizen` and `client`: `saveSearch` (from the search block), `updateSavedSearch`, `pauseSavedSearch`, `deleteSavedSearch`, a collection `mySavedSearches` and a page "Mijn zoekopdrachten".
- `SavedSearchMatchingJob`, every 15 minutes: `immediate` searches every run, `daily` once after 07:00, `weekly` on Monday after 07:00. It runs each query through the same anonymous path as `GET /api/search` and keeps only publications that became public since `lastRunAt`.
- The notice goes through portaliq's change rule: opencatalogi declares `opencatalogi.savedSearch.matched` on `lastNotifiedAt` of `mySavedSearches`. portaliq writes the inbox message and sends email and push by the resident's preferences.

## Hydra requirements implemented

- `woo-citizen-journey`: A saved search MUST notify only about publications the resident could have found.
- `woo-citizen-journey`: Every answer, decision and alert MUST reach the resident through portaliq's notice path (the sender half).
- `woo-citizen-journey`: Removing a portal account MUST remove the resident's dossiers and saved searches (the saved-search half, built in `citizen-collections`).

## Deviations from the contract, found in the code

- C3 says the sender writes a `portalMessage`. portaliq does not dispatch email or Berichtenbox for a message another app writes: `PortalRecordChangeListener::onCreated()` skips portaliq's own messages, and `portalMessage` has no rule key. The path that delivers today is a change rule in the sender's manifest, which portaliq turns into the inbox message plus the email and push dispatch. opencatalogi uses that path and keeps the contract's rule key. Berichtenbox for change rules is the portaliq lane's.
- `lastMatches` (at most 20 `{ publication, title, url, publicationDate }`) and `matchCount` are added to C2. portaliq's change-rule message carries no body of the sender's, so the saved search itself lists what was found.
- One-click unsubscribe (J6.5) is `pauseSavedSearch` on the saved search the notice opens. portaliq's email carries no sender content, so a signed link in the email is not possible on this path.

## Out of scope

The search block and its "Bewaar deze zoekopdracht" button (portaliq, `woo-search-and-detail`).
