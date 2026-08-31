---
sidebar_position: 3
title: View a component in detail
description: Open a software component or publication, read its metadata, and follow the links to related items.
---

# View a component in detail

The detail page for one item — a software component, a publication, a service description — pulls in everything OpenCatalogi knows: the typed metadata, the attached files, the related items, the comment thread, and the audit trail. This tutorial walks through one item end-to-end.

## Goal

By the end you will have opened one item's detail page, read its metadata, downloaded an attached file, and followed a *related item* link.

## Prerequisites

- A catalogue with at least one item (see [Browse a catalogue](02-browse-catalogue.md)).
- The item should ideally have at least one attached file and one related item — most demo items do.

## Steps

1. Open a catalogue, switch to **Publications**, and click any row. The detail page opens with a header card (title, summary, last-updated, status badge), the main *Properties* pane, and a right-hand tab strip.

   ![Item detail header](/screenshots/tutorials/user/03-view-component-detail-01.png)

2. The **Properties** view renders the item's typed metadata — each schema field as a labelled row, with type-aware formatting (dates as dates, URLs as links, enums as chips, references as clickable links into the related item).

   ![Properties pane](/screenshots/tutorials/user/03-view-component-detail-02.png)

3. Switch to the **Files** tab in the sidebar. The tab lists every file attached to this item — name, type icon, size, uploader, version. Click a file row to open the preview pane — PDFs render inline, images load full-size. The **Download** action saves a copy locally.

   ![Files tab with preview](/screenshots/tutorials/user/03-view-component-detail-03.png)

4. Switch to the **Related** tab. The tab lists every other item that references this one, grouped by relation type — *part of*, *depends on*, *implemented by*, *succeeds*. Click a relation to jump to the related item's detail page.

   ![Related items tab](/screenshots/tutorials/user/03-view-component-detail-04.png)

5. Switch to the **Audit** tab. Every change to the item is logged — create, update, delete, file added, relation added. Click a row to expand the before / after diff.

   ![Audit tab with diff](/screenshots/tutorials/user/03-view-component-detail-05.png)

## Verification

The detail header renders the title and summary, the Properties pane lists every schema field with the right type formatting, the Files tab lets you preview / download attachments, the Related tab links to other items (and clicking through opens them), and the Audit tab shows at least the original `create` entry.

## Common issues

| Symptom | Fix |
|---|---|
| Properties pane shows "unknown field" rows | The item's schema has changed since the item was created — open the schema in OpenRegister and back-fill the missing field, or accept the orphans (they don't break the rest of the view). |
| File preview is blank | Nextcloud has no preview generator for that mime type. Download still works. Install the relevant preview app or use the Files generator extension. |
| Related tab is empty for an item you know is referenced | Relations are computed asynchronously after a save — wait 30 s and refresh; persistent emptiness means the relation indexer is stalled (admin runs `occ opencatalogi:reindex-relations`). |

## Reference

- [Link related items](07-link-related-items.md) — add relations from this side.
- [Search across catalogues](04-search-across-catalogues.md) — find items by metadata.
