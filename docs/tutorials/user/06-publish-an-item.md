---
sidebar_position: 6
title: Publish an item
description: Create a new publication in a catalogue, fill in its metadata, attach a file, and publish it.
---

# Publish an item

A *publication* is one item inside a catalogue — a dataset, an open-government report, an API description, a software component, a service description. This tutorial walks through the create → fill → save → publish flow end-to-end.

## Goal

By the end you will have one published item in a catalogue, visible to anyone who can read the catalogue, with at least one attached file.

## Prerequisites

- A catalogue you can write to (the creator and members with the *editor* role can). Ask an admin to set up [permissions and roles](../admin/01-configure-catalogue.md) on the catalogue first if you can't.
- The publication's schema understood — the create form is generated from the schema, so the catalogue's attached schemas decide which fields show up.

## Steps

1. Open the catalogue, switch to the **Publications** tab, click **Add publication**. A schema picker opens if the catalogue has more than one publication schema attached — pick the right one (e.g. *Document*, *Dataset*, *Service*).

   ![Add publication, schema picker](/screenshots/tutorials/user/06-publish-an-item-01.png)

2. The create dialog renders a form generated from the schema. Required fields are marked; helper text explains the format. Fill in the basics — *title*, *summary*, *publisher* (organisation), *language*, *publication date*. Save the draft.

   ![Create dialog with fields](/screenshots/tutorials/user/06-publish-an-item-02.png)

3. The item detail page opens, status *draft*. Switch to the **Files** tab and drop a file onto the upload zone — a PDF report, an Excel dataset, a CSV file. The file lands in the item's Nextcloud folder and shows in the list with name, type, size, uploader.

   ![File attached to draft](/screenshots/tutorials/user/06-publish-an-item-03.png)

4. Switch back to **Properties** and add any missing optional fields — *themes*, *keywords*, *license*, *contact point*. The schema-driven editor validates as you type; required fields glow red until they're filled in.

   ![Form populated, validation green](/screenshots/tutorials/user/06-publish-an-item-04.png)

5. Click **Publish** in the toolbar. The status flips from *draft* to *published*, the *publishedAt* timestamp is set, and the item now shows up in the catalogue's public list. Federated subscribers will pick it up on their next sync.

   ![Item published](/screenshots/tutorials/user/06-publish-an-item-05.png)

## Verification

The item shows in the catalogue's **Publications** tab with status *published*, the **Audit** tab has `create` + `publish` entries, the attached file lives under the item's Nextcloud folder, and the item shows up in **Search** results within a few seconds.

## Common issues

| Symptom | Fix |
|---|---|
| **Add publication** is disabled | The catalogue has no publication schema attached — ask the catalogue owner to attach one (see [Configure a catalogue](../admin/01-configure-catalogue.md)). |
| **Publish** is disabled | Validation is failing on at least one required field — open the **Properties** tab; the offending field carries a red border and an error message. |
| Publish succeeds but the item doesn't show in **Search** | The search index is rebuilding — wait 30 s; persistent absence means the indexer is stalled (admin runs `occ openregister:reindex`). |

## Reference

- [Link related items](07-link-related-items.md) — connect this item to other publications.
- [Export a publication](08-export-publication.md) — round-trip the item out of OpenCatalogi.
- [Configure a catalogue](../admin/01-configure-catalogue.md) — schemas, visibility, and roles per catalogue.
