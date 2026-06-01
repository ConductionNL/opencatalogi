---
status: pr-created
kind: config
---

# Design: OpenCatalogi schema-declared notifications

## Summary

Adds `x-openregister-notifications` to the `catalog` and `listing` domain schemas in
`lib/Settings/publication_register.json`. No PHP, Vue, route, or migration changes.

## Implementation

All changes land in a single file: `lib/Settings/publication_register.json`.

### `catalog` schema — `catalog-stable` rule

Fires when a `catalog` object transitions through the `stable` lifecycle action.
Recipients: manage-ACL holders of the specific catalog object (`object-acl` kind).

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

### `listing` schema — `listing-sync-failed` rule

Fires when a `listing` object transitions through the `obsolete` lifecycle action
(the state a failed federation sync drives a listing into).
Recipients: the `publication-officers` Nextcloud group.

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

## Declarative-vs-imperative decision

This change is purely declarative: the OpenRegister notification engine
(`notification-schema-rules-and-userconfig-prefs`, archived 2026-05-26) reads
`x-openregister-notifications` at runtime and dispatches `nc-notification` on matching
lifecycle transitions. No per-app notification service code is required.

## Caveats

- **Transition wiring (declared-but-dormant):** `DirectoryService.php` currently writes
  `listing.status` directly (not through named OR transitions). The notification rules
  are declared and structurally correct; they will fire once the sync/publish flows invoke
  named lifecycle transitions (`stable`, `obsolete`) rather than setting the field directly.
  The `x-openregister-lifecycle` block is already present on both schemas and names the
  required transition actions.

- **Group name:** `publication-officers` is the assumed group name. Operators must ensure
  this group exists in the deployment, or adjust the name in the register config before
  going live. CMS-config schemas (`page`, `menu`, `theme`, `glossary`, `organization`) and
  `publication` are intentionally excluded — no lifecycle or owner-uid field to target.
