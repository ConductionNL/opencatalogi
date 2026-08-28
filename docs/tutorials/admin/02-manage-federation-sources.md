---
sidebar_position: 2
title: Manage federation sources
description: Add a peer OpenCatalogi instance to the Directory, tune its integration level, and use the federated network for search.
---

# Manage federation sources

Federation in OpenCatalogi is per-peer: each instance you subscribe to via the **Directory** publishes a set of catalogs that your instance can then include in its own search. This tutorial walks the admin-side configuration of one peer end-to-end.

## Goal

By the end you will have added one peer directory, verified the connection, tuned its integration level, and confirmed the peer shows up as a source in federated search.

## Prerequisites

- Admin rights on OpenCatalogi.
- The peer's **directory URL** — typically `https://<peer>/index.php/apps/opencatalogi/api/directory`.
- Outbound HTTPS reach from your Nextcloud server to the peer. Federation is HTTP-only; an outbound firewall block breaks it.

## Steps

1. Open **Directory** in the app navigation. The page lists every peer this instance has subscribed to and shows three status counters — *available*, *degraded*, *unreachable* — along with each peer's directory URL and integration level. Click **Add directory** in the toolbar.

   ![Directory list with three peers and the Add directory button](/screenshots/tutorials/admin/02-manage-federation-sources-01.png)

2. The dialog asks for a single field: the peer's **directory URL**. Paste it and click **Add**. There is no separate *Test connection* step — the sync runs immediately and the dialog reports the result.

   ![Add directory dialog with the peer's directory URL filled in](/screenshots/tutorials/admin/02-manage-federation-sources-02.png)

3. On success the dialog swaps to a confirmation panel with a per-run breakdown: **New listings**, **Updated listings**, **Failed listings**. Zero across the board means the peer responded but had no catalogs to expose; a positive *New* count means catalogs were imported and are now searchable. Click **Close**.

   ![Directory added confirmation showing new, updated, and failed listing counts](/screenshots/tutorials/admin/02-manage-federation-sources-03.png)

4. To retune a peer later, click **Actions → Edit** on its row in the Directory list. The dialog exposes the peer's **integration level** — *Federated search* (this instance queries the peer during search fan-out) or *Connection* (the peer is subscribed but not queried during search). The directory URL is read-only; to point at a different peer, remove this entry and add a new one. Save when done.

   ![Edit listing dialog showing directory URL and integration level dropdown](/screenshots/tutorials/admin/02-manage-federation-sources-04.png)

5. Verify the federation is live by opening **Search** and running any query. The search fans out to every peer with integration level *Federated search* and combines the results with your local publications. If nothing matches, the page reads *No matching publications across the federation* — federation is still working, the peers just have no data on that term.

   ![Search publications page showing the federated search UI](/screenshots/tutorials/admin/02-manage-federation-sources-05.png)

## Verification

- The peer row in **Directory** shows an *available* status dot and a real integration level.
- The three counters at the top of the Directory page reflect the network state — e.g. `2 available · 0 degraded · 1 unreachable`.
- `GET /index.php/apps/opencatalogi/api/listings` returns the peer with a `statusCode: 200` and a recent `lastSync` timestamp.

## Common issues

| Symptom | Fix |
|---|---|
| **Add directory** returns *Failed listings* > 0 | The peer responded but one or more of its catalog descriptors was rejected. Check `data/nextcloud.log` for the schema-validation error, then either fix the peer or ignore the specific catalog. |
| Status stays *unreachable* | The peer's directory URL is not reachable from this server — DNS, TLS, or an outbound firewall rule. `curl` the URL from inside the container to isolate. |
| **Search** returns nothing even after adding a peer | The peer's integration level is *Connection*, not *Federated search*. Open the peer's Actions → Edit and change it. |
| Sync ran but the peer isn't in the list | The peer returned 0 catalog listings (`New: 0, Updated: 0, Failed: 0`). Add the peer's own catalogs on their side first, or point at a different peer. |

## Reference

- [Subscribe to a federated catalog](../user/05-subscribe-to-catalogue.md) — the user-side view of what this enables.
- [Manage admin settings](03-admin-settings.md) — instance-wide federation defaults and the outbound (export) side.
