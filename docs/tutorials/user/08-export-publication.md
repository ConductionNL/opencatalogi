---
sidebar_position: 8
title: Export a publication
description: Export one item — or a whole catalogue — to JSON, Excel, or DCAT for use elsewhere.
---

# Export a publication

Anything you can read in OpenCatalogi you can export — one item, one catalogue, the whole instance. Three serialisation formats: **JSON** (round-trippable, the on-the-wire format), **Excel** (one sheet per schema, ideal for bulk edits), **DCAT** (W3C DCAT-AP for interop with European open-data portals).

## Goal

By the end you will have exported one item to JSON and one whole catalogue to Excel, and read the exported files locally.

## Prerequisites

- A catalogue with at least one item (see [Publish an item](06-publish-an-item.md)).
- Spreadsheet software for the Excel round-trip — Excel, LibreOffice Calc, Google Sheets. JSON / DCAT work in any text editor.

## Steps

1. Open an item's detail page. In the toolbar, click **Actions → Export**. The menu offers **JSON**, **Excel**, and **DCAT**. Pick **JSON**.

   ![Item Actions Export menu](/screenshots/tutorials/user/08-export-publication-01.png)

2. The browser downloads a `<slug>.json` file. Open it — every schema property is one JSON key, files are referenced by URL, relations are inline arrays of target IDs. The shape is the same as the OpenCatalogi REST API response, so anything that consumes the API consumes this file.

   ![Exported JSON opened](/screenshots/tutorials/user/08-export-publication-02.png)

3. Back in OpenCatalogi, go up one level to the catalogue and click **Actions → Export → Excel**. The export dialog asks which schemas to include and whether to include attached files (URLs only, not bytes). Pick *all schemas*, *include file refs*, click **Export**.

   ![Catalogue Excel export dialog](/screenshots/tutorials/user/08-export-publication-03.png)

4. The browser downloads an `.xlsx` file. Open it — one sheet per schema (one row per item, one column per property), one sheet for the catalogue metadata, one sheet for the file references.

   ![Excel export opened](/screenshots/tutorials/user/08-export-publication-04.png)

5. To round-trip the Excel: edit a few cells, save the file, and back in OpenCatalogi click **Actions → Import**, pick the file. The preview dialog shows row counts (created / updated / errors) before you commit. Click **Run import** to write the changes.

   ![Excel import preview](/screenshots/tutorials/user/08-export-publication-05.png)

## Verification

The JSON file opens and contains every property you can see in the UI; the Excel file has one sheet per schema with one row per item; the import dry-run produces the same row count as the export; round-trip edits show up on the re-opened items with a fresh `update` entry in their **Audit** tab.

## Common issues

| Symptom | Fix |
|---|---|
| Export downloads an empty `.json` / `.xlsx` | The item or catalogue is empty, *or* the export ran against an unreadable scope — check the catalogue's visibility. |
| Excel cells show dates as long numbers | Cell formatting issue in the spreadsheet — set the column type to *Date* in the spreadsheet app. OpenCatalogi writes ISO 8601 strings, the round-trip is type-safe. |
| Import preview shows "schema not found" | The instance is missing one of the schemas the file references — switch *Include definitions* on when you exported, or import the schema first via OpenRegister. |

## Reference

- [Subscribe to a federated catalogue](05-subscribe-to-catalogue.md) — federation reads the same JSON shape over HTTP.
- [Manage federation sources](../admin/02-manage-federation-sources.md) — DCAT is also exposed at the federation endpoint.
