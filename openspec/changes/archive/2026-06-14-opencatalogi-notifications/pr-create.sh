#!/usr/bin/env bash
# Run this after pushing the branch to create the draft PR.
# Requires: gh auth login (with codeberg.org host) or FORGEJO_TOKEN
cd "$(git rev-parse --show-toplevel)"
gh pr create \
    --draft \
    --base development \
    --title "feat: schema-declared notifications for catalog and listing (#10)" \
    --body "$(cat <<'EOF'
Closes #10

## Summary
Adds `x-openregister-notifications` to the `catalog` and `listing` domain schemas in `lib/Settings/publication_register.json`. Catalogue manage-ACL holders are notified when a catalogue transitions to `stable`; the `publication-officers` group is notified when a federated listing transitions to `obsolete` (sync failure). Both rules ship bilingual (nl/en) subjects per ADR-007/ADR-025. No PHP, Vue, route, or migration changes — purely declarative via the OpenRegister notification engine. Also fixes a pre-existing duplicate `configuration` key that caused `x-openregister-lifecycle` to be silently shadowed by the second `configuration: {autoPublish: false}` block; lifecycle is now at the schema root, consistent with `x-openregister-notifications`.

## Spec Reference
- Issue: #10
- Spec: `openspec/changes/opencatalogi-notifications/design.md`

## Changes
- `lib/Settings/publication_register.json` — added `x-openregister-notifications` to `catalog` (catalog-stable rule) and `listing` (listing-sync-failed rule); moved `x-openregister-lifecycle` from inside `configuration` to schema root to fix duplicate-key shadowing
- `openspec/changes/opencatalogi-notifications/design.md` — created with status, implementation notes, and caveats
- `openspec/changes/opencatalogi-notifications/tasks.md` — marked all tasks complete with operational caveats documented

## Test Coverage
No PHP/Vue code changed — this is a `kind: config` change (JSON register metadata). The OpenRegister notification engine processes `x-openregister-notifications` at runtime; no unit tests are required per ADR-031. Acceptance criteria verified by JSON schema inspection (all criteria pass).

## Caveats (from design.md)
- `DirectoryService.php` currently writes `listing.status` directly (not through named OR transitions). Notification rules are declared-but-dormant until sync/publish flows invoke named lifecycle transitions (`stable`, `obsolete`).
- Operator must ensure the `publication-officers` group exists in the deployment.
EOF
)"
