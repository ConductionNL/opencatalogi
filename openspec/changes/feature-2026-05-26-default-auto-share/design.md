# Design: Default 'Automatically publish' per schema

## Context

The attachment-upload dialog hands a `share` boolean to OpenRegister's
`POST /apps/openregister/api/objects/{register}/{schema}/{id}/filesMultipart`
endpoint. `share=true` triggers an immediate Nextcloud share link for
each uploaded file; `share=false` keeps the file private (concept).

Today the toggle is always seeded `false`, regardless of which
publication type is selected:

| Surface | File | Currently seeded |
|---|---|---|
| Legacy (dead in `development`) | `opencatalogi/src/modals/generic/UploadFiles.vue` | hardcoded `share: false` |
| Active (manifest-driven detail page) | `@conduction/nextcloud-vue` `CnFilesTab` (rendered inside `CnObjectSidebar`) | no toggle exists yet |

OpenRegister's `Schema` entity stores arbitrary configuration in a JSON
column accessed via `getConfiguration()` / `setConfiguration()`. The
`CnSchemaFormDialog` editor preserves unknown configuration keys
through `{...defaults, ...item}` spread, so a new key can be set today
via the existing JSON pipeline or the schemas API without library
changes.

The existing `configuration.autoPublish` key is **not** the right
home: per
`OpenRegister/lib/Service/Object/SaveObject/MetadataHydrationHandler.php`
it is deprecated and its semantics are "share all attachments when the
parent object is published" (post-hoc bulk action), not "default state
of the per-upload toggle". Reusing it would silently change the
behaviour of any schema that already opts in.

## Decision: new `configuration.defaultAutoShare` key

Add a new boolean `configuration.defaultAutoShare` on the Schema's
`configuration` JSON. Empty / missing / non-truthy values mean "off"
(current behaviour). The upload dialog reads the key on open and
seeds the toggle accordingly. The user can always override per
upload — the schema value never coerces a final value, only the
initial one.

### Why a new key and not `autoPublish`

| Aspect | `autoPublish` (existing, deprecated) | `defaultAutoShare` (new) |
|---|---|---|
| Lifecycle moment | When the parent object is published | When the upload dialog opens |
| What it controls | All currently-attached files at once | The default state of one boolean toggle |
| Status | Deprecated (see `MetadataHydrationHandler::$deprecatedKeys`) | New, supported going forward |
| Risk of reuse | Silently changes behaviour for opted-in schemas | None — additive, default-off |

### Backend requirement: whitelist `defaultAutoShare`

OpenRegister's `Schema::validateConfigurationArray()` (in
`openregister/lib/Db/Schema.php`) does **not** round-trip arbitrary
keys — keys absent from its allow-list (`$stringFields` /
`$boolFields` / `$passThrough` / explicit cases) are silently
dropped on save. The `configuration` column is JSON, but the
validator is gate-keepered.

Therefore the new key needs a one-line addition to the `$boolFields`
whitelist in OpenRegister:

```php
$boolFields = ['allowFiles', 'autoPublish', 'defaultAutoShare'];
```

No migration, no entity field, no typed accessor. Once whitelisted,
the existing JSON column persists the value as-is and
`Schema::getConfiguration()` deserialises it. `CnSchemaFormDialog`
already preserves unknown keys on its end via `{...defaults, ...item}`
spread, so the form round-trips the value without further changes.

Sibling PR carrying the OpenRegister whitelist edit:
**ConductionNL/openregister#feature/577-schema-default-auto-share**.

## Read path

| Step | Where | What |
|---|---|---|
| 1 | `UploadFiles.vue::onOpenModal()` | Calls the new `applySchemaDefaults()` |
| 2 | `applySchemaDefaults()` | Reads `objectStore.getActiveObject('publication')['@self'].schema` |
| 3 | (a) inflated schema object | If the object already carries a full schema, read `schema.configuration.defaultAutoShare` directly |
| 3 | (b) bare schema id | Otherwise fetch `/index.php/apps/openregister/api/schemas/{id}` and read the same path |
| 4 | seed | Set `this.share = (configuration?.defaultAutoShare === true)` |
| 5 | failure | Any network error, missing publication, missing schema, or missing key → keep the safe default (`false`) |

The same logic mirrors in `CnFilesTab` (sibling repo) via the
`defaultShare: null` auto-detect path: when `defaultShare` is null,
`CnFilesTab` performs the same schema fetch + key read. A non-null
`defaultShare` (Boolean) prop always wins over the schema lookup so
that callers can override.

## Failure mode: fail-closed

The seed is always one of two values: schema's `true` or the safe
default `false`. A network hiccup, a 404 on the schema endpoint, a
malformed JSON body, or a missing `configuration` block never flips
the seed to `true`. This matches the principle that automation
toward "share / publish more" should require an explicit signal, not
the absence of one.

## Out-of-scope: schema editor UI

A first-class checkbox on `CnSchemaConfigurationTab.vue` in
`@conduction/nextcloud-vue` would be the ergonomic way to set this
key. The form already preserves the field thanks to the spread
operator, so a follow-up issue can land that checkbox without
breaking schemas already opted-in.

## Out-of-scope: dead-code reachability of UploadFiles.vue

In the current `development` branch of opencatalogi, `UploadFiles.vue`
is not mounted by any active route (`src/modals/Modals.vue` /
`src/dialogs/Dialogs.vue` are themselves unused). The change in this
app is therefore preserved as a reference implementation, kept in
lockstep with the `CnFilesTab` PR so a future re-wire (or any legacy
consumer still importing the modal) lands the same behaviour.
