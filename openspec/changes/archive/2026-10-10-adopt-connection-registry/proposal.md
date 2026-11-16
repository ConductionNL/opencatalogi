---
kind: code
---

# Proposal: adopt-connection-registry

## Why

OpenCatalogi talks to three outside systems, and an admin can only tell whether they work by reading the log.

- **Federation directories.** A sync reads listings from directory.opencatalogi.nl and every peer directory it knows. A peer can fail for days before anyone notices.
- **Directory broadcast.** Every four hours, and for every new directory a sync finds, OpenCatalogi tells peers that this catalogue exists. A development instance refuses to broadcast a local address, and only the log says so.
- **Woo-index harvester.** The KOOP harvester reads robots.txt, the DIWOO sitemaps and the publications of this instance. The readiness check shows the result inside its own settings section only.

Hydra change `connection-registry` (hydra#667, amended in hydra#673, hydra#674 and hydra#676) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with three connections: `directory`, `broadcast` and `woo-index`. All three are `reportedOnly`.
- The Federation sync and Woo-index harvester readiness sections get stable ids: `section-federation-sync` and `section-woo-index`.
- A directory sync reports its outcome, from the cron job and the Sync directories now button alike.
- A broadcast reports its outcome, throttled to once an hour for the same status and once every five minutes for a changed one.
- A readiness check reports its outcome, the 409 for a missing Woo catalog included.
- A Woo-index registration save refreshes `woo-index`. A setup save of `default_directory_url` refreshes `directory`.
- An Integrations page under the settings gear, over integriq's `app_connection` schema, preset to `app=opencatalogi`, admin only, and only shown when integriq is installed.
- Add integration opens `/apps/integriq/connections?app=opencatalogi&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, and the strings in English and Dutch.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D3, D4, D6, D8, D9 and D12.
- integriq on `development`: the `app_connection` schema, the declaration sync, both events and the Connections overview.

Without integriq the menu entry is hidden, a deep link shows the missing-dependency screen, and nothing is sent.

## Out of scope

- Federation search. It calls peer directories on every search request, so a report from it would be a report per request (ADR-076).
- Sitemap and DCAT output. Those are endpoints others call, not calls OpenCatalogi makes.
- OpenRegister. It runs in the same instance and is a hard dependency, not an outside connection.

## Rollback

Revert the change. OpenCatalogi writes no rows of its own. Integriq removes the rows without a linked source on its next sync. The report memory key `connection_report_broadcast` can stay; nothing else reads it.
