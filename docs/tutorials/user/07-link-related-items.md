---
sidebar_position: 7
title: Link related items
description: Add typed relations between publications so the catalogue shows the connections — part-of, depends-on, succeeds.
---

# Link related items

Items are often *part of* a larger thing, *depend on* another, or *succeed* an older version. OpenCatalogi stores these connections as typed relations between items — bidirectional, audit-logged, queryable from the search facets.

## Goal

By the end you will have added one typed relation between two items and confirmed it shows on both the *source* and the *target* item's **Related** tab.

## Prerequisites

- Two items in catalogues you can write to (see [Publish an item](06-publish-an-item.md)).
- A rough idea of what relation type to use — the publication schema decides which types are allowed. Defaults are *part-of*, *depends-on*, *implements*, *succeeds*, *related-to*.

## Steps

1. Open the source item's detail page and switch to the **Related** tab. The tab shows the existing relations grouped by type; on a new item the list is empty. Click **Add relation**.

   ![Related tab with Add relation button](/screenshots/tutorials/user/07-link-related-items-01.png)

2. The dialog asks for **relation type** (a dropdown of the schema-allowed types), the **target item** (a typeahead search across every item you can read — local + federated), and an optional **note**. Pick *depends-on* and start typing the target item's title.

   ![Add relation dialog](/screenshots/tutorials/user/07-link-related-items-02.png)

3. Select the target from the typeahead dropdown. The dialog confirms the relation summary — *this item depends-on target item* — with a *Save* button. Click it.

   ![Relation summary, ready to save](/screenshots/tutorials/user/07-link-related-items-03.png)

4. The dialog closes and the relation appears in the source's **Related** tab under the *depends-on* heading. Click the target item link to jump to its detail page. Its **Related** tab carries the inverse relation — *this item is depended on by source item* — automatically.

   ![Inverse relation on target](/screenshots/tutorials/user/07-link-related-items-04.png)

5. Both items' **Audit** tabs log a `relation_added` entry. Removing the relation (the **×** next to the row on either side) writes a `relation_removed` entry and clears the inverse.

   ![Audit entries on both items](/screenshots/tutorials/user/07-link-related-items-05.png)

## Verification

The source item's **Related** tab lists the new relation under the right heading, the target item's **Related** tab lists the inverse relation, both items' **Audit** tabs log a `relation_added` entry within a second of the save, and **Search** finds the connection — filter by *related-to* facet.

## Common issues

| Symptom | Fix |
|---|---|
| Typeahead finds nothing for a target you know exists | The target is in a catalogue you can't read (private), or in a federated catalogue that isn't yet synced — see [Subscribe to a federated catalogue](05-subscribe-to-catalogue.md). |
| Relation type dropdown is empty | The publication schema doesn't define any relation types — open the schema in OpenRegister and add at least one `relations` property. |
| Inverse relation doesn't show on the target | The inverse indexer is asynchronous; refresh after 30 s. Persistent absence means the indexer is stalled (admin runs `occ opencatalogi:reindex-relations`). |

## Reference

- [View a component in detail](03-view-component-detail.md) — the full *Related* tab tour.
- [Search across catalogues](04-search-across-catalogues.md) — relations expose as facets on the search side.
