---
sidebar_position: 2
title: Browse a catalogue
description: Open a catalogue, look through its publications, and use the filter panel to narrow the list.
---

# Browse a catalogue

A *catalogue* groups related items — *publications*, *components*, *organisations*, *services* — into one navigable view. This tutorial opens one catalogue and walks through the list / filter / detail flow.

## Goal

By the end you will have opened a catalogue, narrowed its list with a filter, sorted the rows, and reached the detail page of one item.

## Prerequisites

- OpenCatalogi opened (see [Open OpenCatalogi for the first time](01-first-launch.md)).
- At least one catalogue with a few items in it. On a fresh install, ask an admin to import the demo catalogue or follow [Configure a catalogue](../admin/01-configure-catalogue.md).

## Steps

1. Open **Catalogi** in the navigation. The list shows the catalogues you can read — each one as a card with title, summary, the kind of items it carries (publications / components / services), the visibility badge, and last-updated time. Click a card.

   ![Catalogi list with cards](/screenshots/tutorials/user/02-browse-catalogue-01.png)

2. The catalogue detail page opens — a header with the catalogue's name, logo, organisation, and a tab strip: **Overview**, **Publications**, **Themes**, **Subscribers**, **Audit**. The **Publications** tab is selected by default and renders the publication list.

   ![Catalogue detail page, Publications tab](/screenshots/tutorials/user/02-browse-catalogue-02.png)

3. Use the **filter panel** on the right to narrow the list — by *theme*, *organisation*, *publication type*, *language*, *publication date range*. Filters AND together. Use the search box in the toolbar for full-text search within this catalogue.

   ![Filter panel applied](/screenshots/tutorials/user/02-browse-catalogue-03.png)

4. Click a column header to sort — by *title*, *publication date*, *last modified*, or any property the schema marks as sortable. Switch the **Cards / Table** toggle to compare the dense table layout against the card view.

   ![Sorted table view](/screenshots/tutorials/user/02-browse-catalogue-04.png)

5. Click any row to open the **item detail page**. The right-hand sidebar shows the item's properties, attached files, related items, and a comments thread.

   ![Item detail page](/screenshots/tutorials/user/02-browse-catalogue-05.png)

## Verification

The catalogue detail page renders without errors, the filter panel narrows the list when you tick a value, the sort arrows in the table header reorder the rows, and clicking a row opens its detail page with a populated sidebar.

## Common issues

| Symptom | Fix |
|---|---|
| Catalogue card opens to "Catalogue not found" | The catalogue has been deleted on a federated source — refresh the federation sync (see [Manage federation sources](../admin/02-manage-federation-sources.md)) or remove the broken card. |
| Filter panel is empty | The catalogue has no items yet — no values to filter on. Add a publication via [Publish an item](06-publish-an-item.md). |
| Items show "title only", every other column empty | The publication schema attached to this catalogue has only one indexed property — open the schema in OpenRegister and mark more properties as searchable. |

## Reference

- [View component detail](03-view-component-detail.md) — what each item type can carry.
- [Search across catalogues](04-search-across-catalogues.md) — when you don't know which catalogue holds the item.
- [Configure a catalogue](../admin/01-configure-catalogue.md) — when you need to set up your own.
