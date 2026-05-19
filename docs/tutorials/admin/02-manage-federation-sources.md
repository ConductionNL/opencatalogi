---
sidebar_position: 2
title: Manage federation sources
description: Add a remote OpenCatalogi instance as a federation source, schedule the sync, and tune what gets pulled.
---

# Manage federation sources

Federation is two-way and per-peer. A *federation source* is the inbound side — your instance subscribes to a remote one and pulls its catalogues. This tutorial walks the admin-side configuration of one source end-to-end: connection, scope, schedule, auth.

## Goal

By the end you will have one federation source configured, one successful sync run, and at least one remote catalogue visible alongside your local ones.

## Prerequisites

- Admin rights on OpenCatalogi.
- The remote instance's **OpenCatalogi API base URL** (typically `https://<instance>/index.php/apps/opencatalogi/api`) and, if the remote requires it, an **API key** or **OAuth client**.
- Network reach from your Nextcloud server to the remote — federation is HTTP-only, so an outbound firewall block breaks it.

## Steps

1. Open **Directory** in the navigation. The view lists every known federation peer with **name**, **URL**, **catalogue count**, **last seen** timestamp, **status**. Click **Add source** in the toolbar.

   ![Directory with Add source button](/screenshots/tutorials/admin/02-manage-federation-sources-01.png)

2. The dialog asks for **name** (free-text), **base URL** (the peer's OpenCatalogi API root), and **auth type** (*none*, *API key*, *OAuth2 client credentials*, *mTLS*). Pick *none* for a public instance, fill in the credentials for the others. Click **Test connection** — the dialog reports the peer's catalogue count on success.

   ![Source detail with test-connection result](/screenshots/tutorials/admin/02-manage-federation-sources-02.png)

3. Switch to the **Scope** tab. Pick which of the peer's catalogues to pull — *all*, *selected* (a checklist), or *by tag* (any catalogue tagged with one of the listed tags). Pick *selected* for a typical first run so you can iterate.

   ![Scope tab with catalogues selected](/screenshots/tutorials/admin/02-manage-federation-sources-03.png)

4. Switch to the **Schedule** tab. Pick *manual*, *every N minutes*, *hourly*, *daily*, or a custom cron expression. Set a **TTL** (how long to keep federated items if the source goes away) — `30 days` is a safe default. Save the source.

   ![Schedule tab](/screenshots/tutorials/admin/02-manage-federation-sources-04.png)

5. From the source detail, click **Sync now**. The toolbar runs through *Connecting → Fetching → Indexing → Done* and reports the item count. Switch to **Catalogi** in the main navigation — the pulled catalogues show up with a *federated* badge.

   ![Sync complete, federated catalogues visible](/screenshots/tutorials/admin/02-manage-federation-sources-05.png)

## Verification

The source row in **Directory** shows status *active* and a recent *last sync* timestamp; the **Catalogi** list shows the pulled catalogues with the *federated* badge; their publications appear in **Search**; re-running the sync produces 0 changes when nothing on the source side has moved.

## Common issues

| Symptom | Fix |
|---|---|
| **Test connection** fails with TLS error | Self-signed cert on the peer — install the CA chain on your Nextcloud server or set the source to *Skip TLS verification* (only on trusted networks). |
| Sync reports 0 errors but no items appear | The peer's catalogues are *Internal* / *Private* — bump their visibility on the peer side, or use a per-peer share token. |
| Federated items vanish after a week | TTL on the source has expired — re-run *Sync now* to refresh, or extend the TTL on the **Schedule** tab. |
| Scheduled sync never runs | Nextcloud cron isn't active — `php occ background:cron` should be running every 5 min. |

## Reference

- [Subscribe to a federated catalogue](../user/05-subscribe-to-catalogue.md) — the user-side view of what this enables.
- [Manage admin settings](03-admin-settings.md) — instance-wide federation defaults and the outbound (export) side.
