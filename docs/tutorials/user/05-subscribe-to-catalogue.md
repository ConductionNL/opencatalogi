---
sidebar_position: 5
title: Subscribe to a federated catalogue
description: Add a remote OpenCatalogi instance as a source so its catalogues show up in your search and lists.
---

# Subscribe to a federated catalogue

Federation is the *open* in OpenCatalogi — every instance can expose its catalogues to other instances, and any instance can subscribe to read those catalogues alongside its own. Subscriptions are read-only: the source instance stays the source of truth.

## Goal

By the end you will have subscribed to one remote catalogue, confirmed that its items appear in your **Search** and in your **Catalogi** list, and read one of its publication detail pages.

## Prerequisites

- The federation directory loaded (`/apps/opencatalogi/directory`). On a default install this lists the Conduction-curated peers; an admin can add private peers (see [Manage federation sources](../admin/02-manage-federation-sources.md)).
- Network reach from your Nextcloud server to the remote OpenCatalogi instance — federation is HTTP, so any firewall block on outbound 443 breaks it.

## Steps

1. Open **Directory** in the navigation. The view lists every known peer with its **name**, **URL**, **number of catalogues**, **last seen** timestamp, and a **status** badge (*online / unreachable*). Use the search box at the top to find a specific peer.

   ![Directory of federation peers](/screenshots/tutorials/user/05-subscribe-to-catalogue-01.png)

2. Click a peer to open its detail page. The page lists every catalogue the peer exposes — title, summary, item count, organisation. Each one has a **Subscribe** button.

   ![Peer detail with subscribe button](/screenshots/tutorials/user/05-subscribe-to-catalogue-02.png)

3. Click **Subscribe** on one catalogue. A dialog confirms the source URL and asks whether to sync immediately or schedule the first sync. Pick *Sync now* for a first run — the dialog progresses through *Connecting → Fetching → Indexing → Done* and reports the item count.

   ![Subscribe sync dialog](/screenshots/tutorials/user/05-subscribe-to-catalogue-03.png)

4. Back in your local **Catalogi** list, the subscribed catalogue shows up alongside your local ones — it carries a *federated* badge to mark the difference, and the source peer is shown in the metadata header.

   ![Catalogi list with subscribed catalogue](/screenshots/tutorials/user/05-subscribe-to-catalogue-04.png)

5. Open the subscribed catalogue and click any publication. The detail page renders the same as a local item — the typed properties, the attached files, the related items — but read-only. The audit tab attributes the changes to the source peer rather than to a local user.

   ![Federated item detail, read-only](/screenshots/tutorials/user/05-subscribe-to-catalogue-05.png)

## Verification

The subscribed catalogue shows up in your **Catalogi** list with a *federated* badge, its publications appear in your **Search** results, opening an item renders the full detail page in read-only mode, and the federation-source detail page shows a *last sync* timestamp.

## Common issues

| Symptom | Fix |
|---|---|
| Subscribe dialog hangs on *Connecting* | The remote URL is unreachable — `curl` it from your Nextcloud server. TLS cert errors are the most common cause; install the CA chain or set the source to *Skip TLS verification* (only on trusted networks). |
| Sync reports *Done* but no items appear | The remote catalogue is empty, *or* the schema versions don't match and every item failed validation. Switch on *Detailed sync log* on the federation source and re-run. |
| Subscribed items disappear after a week | The subscription has a TTL set on the admin side — see [Manage federation sources](../admin/02-manage-federation-sources.md). |

## Reference

- [Manage federation sources](../admin/02-manage-federation-sources.md) — for the admin-side controls (schedule, TTL, auth).
- [Search across catalogues](04-search-across-catalogues.md) — subscribed catalogues join the same search index.
