---
kind: code
depends_on: []
---

# Proposal: citizen-collections

## Why

A resident who finds Woo publications on the portal cannot keep them. There is no place to collect a publication or one of its documents, write a note next to it, share the set with someone else, or start a question or a Woo request from it. The Woo citizen journey (hydra `openspec/changes/woo-citizen-journey`, contract C1, journeys J3.1 to J3.5) makes this dossier the hub: pipelinq asks about it (C4), dossiq starts a request from it and adds the decision back to it (C5, C6).

Ruben decided on 30 September 2026: dossiers are for signed-in residents, private, shareable by a revocable read-only link, and stored in opencatalogi.

opencatalogi has no portal contribution today, so the portal cannot offer anything from this app to a resident.

## What changes

- A new schema `collection` in the `publication` register, shaped as contract C1: `title`, `description`, `owner`, `items[]`, `share`, `sourceOf[]`.
- opencatalogi's first `lib/Portal/PortalContributionProvider.php`, for the audiences `citizen` and `client`. It declares the resident's dossiers as a collection scoped by `owner`, a page "Mijn dossiers", and the dossier actions.
- Endpoint actions that portaliq forwards with its signed `X-Portal-Subject` assertion: add to a dossier (existing or new), remove an item, write a note, share, revoke the share, delete, and the owner's view. opencatalogi verifies the assertion and checks `owner` itself. A mismatch answers 404.
- A public read of a shared dossier at `GET /index.php/apps/opencatalogi/api/collections/shared/{token}`. It returns only items whose publication is public at that moment, with notes, without the owner.
- The owner's view marks an item whose publication is no longer public, instead of dropping it.
- When a portal account is removed, opencatalogi deletes that subject's collections (and, with `saved-searches-and-alerts`, saved searches).

## Hydra requirements implemented

- `woo-citizen-journey`: A resident's dossier MUST be owned by the resident and readable by nobody else unless shared.
- `woo-citizen-journey`: A shared dossier MUST show only what is public at the moment it is read.
- `woo-citizen-journey`: Removing a portal account MUST remove the resident's dossiers and saved searches.

## Deviations from the contract, found in the code

- There is no portaliq account-removal event. `removeAccount` sets the `portalAccount` object to `status: removed`. opencatalogi listens for that OpenRegister update instead (design.md, D5).
- Items carry an optional `title`, a snapshot of the publication title when the item was added. The owner still sees what a depublished item was, and pipelinq's snapshot (C4) needs no second read. It is additive: the contract's item fields are unchanged.

## Out of scope

The portal screens themselves (portaliq, `my-dossiers`), asking about a dossier (pipelinq, C4), starting a Woo request (dossiq, C5).
