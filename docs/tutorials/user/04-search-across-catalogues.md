---
sidebar_position: 4
title: Search across catalogues
description: Run a federated full-text + facet search across every catalogue you can see — local and subscribed.
---

# Search across catalogues

The **Search** view is OpenCatalogi's central find-tool — full-text across every catalogue you can read (local and federated), facet filters on theme / organisation / type / language, and saved searches you can pin to the navigation.

## Goal

By the end you will have run one cross-catalogue query, narrowed it with two facets, and saved the query as a reusable search.

## Prerequisites

- A few catalogues with items to search against — local-only is fine. If your instance has no items, follow [Configure a catalogue](../admin/01-configure-catalogue.md) and [Publish an item](06-publish-an-item.md) first, or ask an admin to import the demo data.

## Steps

1. Open **Search** in the navigation. The view opens with an empty search box, a facet panel on the right, and an empty result area. The facet panel lists every catalogue / schema / property that's indexed and reachable.

   ![Search view, empty](/screenshots/tutorials/user/04-search-across-catalogues-01.png)

2. Type a few characters into the search box. Results stream in as you type — full-text across every searchable property of every item you can read, regardless of which catalogue holds it. The result count updates live in the toolbar.

   ![Full-text search results](/screenshots/tutorials/user/04-search-across-catalogues-02.png)

3. Expand a facet (for example *theme* or *organisation*) and tick a value. The result list narrows to items that match the text query **and** the facet. Add a second facet — facets AND together. A *Reset* link clears them all.

   ![Two facets applied](/screenshots/tutorials/user/04-search-across-catalogues-03.png)

4. Click a column header to sort, switch the **Cards / Table** toggle, or open a result to read it in place via a preview drawer. The drawer shows the same metadata as the full detail page without leaving the search context.

   ![Result preview drawer](/screenshots/tutorials/user/04-search-across-catalogues-04.png)

5. Click **Save search** in the toolbar. Give it a title and an optional description; pick whether to share it with everyone or keep it private. The saved search shows in the left navigation under **Search**, so you can re-run the same query later.

   ![Save search dialog](/screenshots/tutorials/user/04-search-across-catalogues-05.png)

## Verification

The toolbar shows a non-zero result count for a real query, the facet panel reflects the applied filters, the saved search appears in the left navigation, and clicking the saved search re-runs the exact same query + facet + sort combination.

## Common issues

| Symptom | Fix |
|---|---|
| Search returns nothing for a term you can see in an item | The property is `searchable: false` in the catalogue's schema — ask the catalogue owner to flip it. |
| Facets show only your own catalogues | The federated peers aren't reachable — see [Manage federation sources](../admin/02-manage-federation-sources.md). |
| Result count fluctuates while you type | Normal behaviour during incremental indexing — the count settles once the typing pauses. |

## Reference

- [Feature notes](../../features/README.md) — search backend and faceting are inherited from OpenRegister.
- [Subscribe to a federated catalogue](05-subscribe-to-catalogue.md) — widen what search can reach.
