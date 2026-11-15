---
sidebar_position: 1
title: Open OpenCatalogi for the first time
description: Open the Catalogi app, find your way around the navigation, and confirm the catalogues you can see.
---

# Open OpenCatalogi for the first time

A first look at OpenCatalogi — where the app lives, what the navigation gives you, and how to tell it is wired up to OpenRegister and the federated network.

## Goal

By the end you will have opened the Catalogi app, recognised the dashboard and the left-hand navigation, and confirmed that the **Catalogi** and **Search** views load.

## Prerequisites

- A Nextcloud account on an instance where the **OpenCatalogi** app is installed and enabled.
- The **OpenRegister** app installed and enabled — OpenCatalogi stores its objects (catalogues, publications, themes, organisations) in registers, so it is a hard dependency. The app installer enables OpenRegister for you on first install.

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Catalogi**. You land on the OpenCatalogi dashboard.

   ![OpenCatalogi dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. The dashboard shows the high-level counters — total catalogues, publications, organisations, themes — and a feed of recent activity. On a fresh install the counters read `0`; they update as you (or a federation source) add items.

   ![Dashboard counters](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. Directly below **Dashboard** you find one entry for every catalogue you have access to, each under the catalogue's own title; when there are no catalogues yet, there are no such entries. Clicking one opens the publications page for that catalogue, where **Add Publication** creates a new one, and keeps the entry highlighted while you work there. Under the heading **Publications**, that page lists everything in the registers and schemas configured on the catalogue in one list, newest first, such as its publications and publication rules. A publication opens on its detail page; an item of another schema opens in the form for that schema. The list follows along when a catalogue is created, renamed or deleted, without reloading the page. Below the catalogues sit **Search**, **Catalogi** (managing the catalogues themselves) and **Directory** (federation peers). Documentation, the store, reports and the roadmap are pinned to the bottom of the navigation, and the gear opens the settings.

   ![OpenCatalogi navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Catalogi**. The list opens with a *Cards / Table* toggle and an **Add Catalogue** button. Existing catalogues show as cards with title, summary, item count, visibility badge and last-updated time. An empty install shows *No catalogi found* — expected until someone creates the first one (or subscribes to a federated peer).

   ![Catalogi list](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the OpenCatalogi dashboard renders without an error banner, the left navigation lists the entries above, and clicking through to **Catalogi** shows either rows or a clean *No catalogi found* state — not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Enable OpenRegister (`occ app:enable openregister`) and reload Catalogi. |
| Dashboard loads but every counter shows `0` | Expected on a fresh install — move on to [Browse a catalogue](02-browse-catalogue.md). |
| Catalogi is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [Browse a catalogue](02-browse-catalogue.md) — the next step.
- [Configure a catalogue](../admin/01-configure-catalogue.md) — for whoever runs the instance.
- [Feature notes](../../features/README.md) — what's wired up and what's experimental.
