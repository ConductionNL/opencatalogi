---
sidebar_position: 1
title: Configure a catalogue
description: Create a catalogue, attach schemas, set visibility, and assign roles so users can publish into it.
---

# Configure a catalogue

Setting up a fresh catalogue is the most common admin task — a new dataset, a new compliance register, a new federation export — and the steps are always the same: create the shell, attach the right schemas, set visibility, assign roles. This tutorial walks through one end-to-end.

## Goal

By the end you will have one catalogue ready for publication: schemas attached, visibility set, three roles configured (*viewer*, *editor*, *admin*), at least one user assigned per role.

## Prerequisites

- Admin rights on OpenCatalogi (Nextcloud admin, or the *catalogue-admin* role).
- OpenRegister set up with at least one publication schema you want to attach (see [Create and attach a schema](https://openregister.conduction.nl/docs/tutorials/user/03-create-a-schema) in the Open Register docs).
- At least two Nextcloud users / groups to assign — `editors` and `viewers` are reasonable.

## Steps

1. Open **Catalogi** in the navigation and click **Add Catalogue**. The *Create Catalogue* dialog asks for **title**, **slug** (auto-suggested from the title), **summary**, **organisation** (the owning org, picked from the *Organisations* list), and an optional **logo**. Fill them in and save.

   ![Create Catalogue dialog](/screenshots/tutorials/admin/01-configure-catalogue-01.png)

2. The catalogue detail page opens, on the **Overview** tab. Switch to **Schemas**. Click **Attach schema**, pick the publication schema(s) you want to allow in this catalogue (you can attach more than one — *Document*, *Dataset*, *Service*). Each attached schema appears as a chip.

   ![Schemas attached](/screenshots/tutorials/admin/01-configure-catalogue-02.png)

3. Switch to **Settings**. The *Visibility* block has three radio options — *Private* (assigned users / groups only), *Internal* (any logged-in user), *Public* (anyone, federation-readable). Pick *Internal* for a typical org-wide catalogue.

   ![Visibility set to Internal](/screenshots/tutorials/admin/01-configure-catalogue-03.png)

4. Still on **Settings**, scroll to *Roles*. Three default roles are pre-created: *viewer* (`read`), *editor* (`read`, `create`, `update`), *admin* (everything including settings). Click **Add member**, pick a user or group, choose a role. Add at least one entry per role.

   ![Members assigned to roles](/screenshots/tutorials/admin/01-configure-catalogue-04.png)

5. Save. Log out and back in as a user assigned the *editor* role — they can read the catalogue, add publications, edit existing ones, but the **Settings** tab is hidden. As a *viewer* the **Add publication** button is greyed out.

   ![Editor view of the catalogue](/screenshots/tutorials/admin/01-configure-catalogue-05.png)

## Verification

The catalogue appears in **Catalogi**, the **Schemas** tab lists the right attached schemas, the **Settings** tab shows the visibility and the three roles with the right members; an *editor* user can publish, a *viewer* can read but not write; the catalogue's **Audit** tab logs every settings change.

## Common issues

| Symptom | Fix |
|---|---|
| Schema picker is empty | OpenRegister has no schemas yet, or the user doesn't have read access to them — check OpenRegister's *Schemas* list, attach yourself to the org that owns them. |
| Members can read but every save fails 403 | The catalogue's role has `read` but not `update`, *or* the publication schema is marked read-only in OpenRegister. |
| Federation peers can't reach the catalogue | Visibility is set to *Internal*, which doesn't expose to federation — bump it to *Public* (or use a per-peer share — see [Manage federation sources](02-manage-federation-sources.md)). |

## Reference

- [Manage federation sources](02-manage-federation-sources.md) — for federation-side configuration.
- [Manage admin settings](03-admin-settings.md) — instance-wide defaults (default visibility, default role).
- [Publish an item](../user/06-publish-an-item.md) — what your editors do with the catalogue.
