---
kind: config
---

# OpenCatalogi — schema-declared notifications

## Why

OpenCatalogi is a publication / federated-catalogue app for government
publication officers and portal editors. The events they care about are
**a catalogue going stable** (publication milestone) and **a federated
listing's sync failing or going stale** (operational health — a broken
listing silently drops content from the portal).

The OpenRegister notification engine (shipped in the `openregister`
change `notification-schema-rules-and-userconfig-prefs`, archived
2026-05-26) consumes a top-level `x-openregister-notifications` key on a
schema and dispatches `nc-notification` on the configured trigger.
Declaring rules on the domain schemas (`catalog`, `listing`) gives
editors timely feedback with no per-app notification code.

This is a configuration change to
`lib/Settings/publication_register.json`. No PHP/Vue changes.

## What Changes

Add `x-openregister-notifications` to the **domain** schemas only. The
register also holds CMS-config objects (`page`, `menu`, `theme`,
`glossary`, `organization`) — these are configuration, not domain data,
and are deliberately **not** notified on.

Neither `catalog` nor `listing` carries a structured owner-uid field, so
recipients use `object-acl` (manage ACL holders) for ownership-scoped
rules and `groups` for ops-team rules.

### `catalog` — published to stable

`catalog.status` is an enum `development | beta | stable | obsolete`.
Reaching `stable` is the publication milestone. Expressed via
`transition` (no engine-gap dependency).

```jsonc
"x-openregister-notifications": {
  "catalog-stable": {
    "trigger": { "type": "transition", "action": "stable" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Catalogus {{title}} is gepubliceerd (stabiel)",
      "en": "Catalogue {{title}} is published (stable)"
    }
  }
}
```

### `listing` — federation sync failed

`listing` carries `status` (enum `development | beta | stable |
obsolete`), `statusMessage`, `statusCode`, and `lastSync`. A failed
federation sync is the operational event editors must hear about. The
sync process drives the listing to an `obsolete` (failed/stale) state;
expressed via `transition` to that action so no `updated`-field-change
engine gap is needed. Routed to the publication-ops group.

```jsonc
"x-openregister-notifications": {
  "listing-sync-failed": {
    "trigger": { "type": "transition", "action": "obsolete" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "groups", "groups": ["publication-officers"] } ],
    "subject": {
      "nl": "Synchronisatie van listing {{title}} mislukt: {{statusMessage}}",
      "en": "Sync of listing {{title}} failed: {{statusMessage}}"
    }
  }
}
```

### `publication` — not notified on (deliberate)

`publication` has no `status`/lifecycle field (only `title`, `summary`,
`description`, `organization`, `themes`) and no structured owner uid, so
neither a `transition` nor a precise `field`-recipient rule resolves
today. A `created` notification on every publication would be noisy and
mis-targeted. Left out until a lifecycle field and/or owner uid exists —
see Caveats.

## Capabilities

- Catalogue manage-ACL holders are notified when a catalogue reaches
  `stable`.
- The `publication-officers` group is notified when a federated listing
  sync fails, with the failure message inlined.
- All rules ship `enabled: true`; users override per `(schema, rule)`
  via OpenRegister's override-only user-config prefs.
- Subjects ship in Dutch and English (ADR-007 / ADR-025).
- CMS-config schemas (page/menu/theme/glossary/organization) are not
  notified on.

## Impact

- Affected file: `lib/Settings/publication_register.json` (`catalog`
  and `listing` schemas gain a `x-openregister-notifications` key).
- No PHP, Vue, route, or migration changes.
- Runtime dependency on the OpenRegister notification engine
  (`notification-schema-rules-and-userconfig-prefs`, already archived).
- The `listing-sync-failed` rule fires only if the sync process drives
  status through a named transition action — see Caveats.

## Caveats

- **Transition actions must be wired by the sync/publish flows.** The
  `transition` trigger fires on a named lifecycle action, not a raw
  `status` write. For `catalog-stable` and `listing-sync-failed` to
  fire, the catalogue-publish and listing-sync code must drive status
  through OpenRegister transition actions named `stable` and `obsolete`
  respectively. If they write `status` directly, these rules are
  declared-but-dormant.
- **No structured owner uid on `catalog` / `listing`.** Recipients use
  `object-acl` (catalog) and `groups` (listing ops) rather than
  `field`. The `publication-officers` group is assumed to exist in the
  deployment; adjust the group name to match the operator's directory.
- **`publication` is intentionally excluded** — no lifecycle field and
  no owner uid to target. Revisit if a `status` or `ownerUid` field is
  added to the `publication` schema.
- **CMS-config schemas excluded by design** — `page`, `menu`, `theme`,
  `glossary`, `organization` are configuration objects, not domain
  data; no notifications declared on them.
- The `updated` trigger has no field-changed condition yet (the engine
  change `notification-updated-field-change-condition` adds it); this
  change uses only `transition` to avoid that dependency.
