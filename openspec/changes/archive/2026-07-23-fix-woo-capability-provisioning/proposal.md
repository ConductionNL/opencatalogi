---
kind: code
depends_on: []
---

# Proposal: fix-woo-capability-provisioning

## Summary
Provision the storage the WOO transparency capability has always needed but
never got. Today the entire WOO workflow is **orphaned**: `WooService` (993
lines), `WooController`, `WooBatchDetail.vue` and `WooRedactionView.vue` all
ship and the `woo-transparency` spec is marked done, but the OpenRegister
schemas those objects live in do not exist, the config keys pointing at them
are never populated, and the frontend's `@resolve:` sentinels for those keys
are never substituted. The visible symptom is the `/woo` view rendering
"No items found" while the console logs two 404s. This change ships the two
missing schemas, auto-configures the three WOO config keys, surfaces them as
initial state, and adds a parity test that prevents the whole defect class
from recurring.

## Motivation
Observed live on the dev instance (2026-07-23 UX audit): opening `/woo` fires

```
GET /apps/openregister/api/schemas/@resolve:woo_batch_schema            → 404
GET /apps/openregister/api/objects/@resolve:woo_register/@resolve:woo_batch_schema → 404
```

The literal sentinel strings reach the network because nothing resolved them.
Tracing back, three coupled defects stack up:

1. **No storage schemas.** `lib/Settings/publication_register.json` declares 10
   schemas (`publication`, `document`, `catalog`, `listing`, `organization`,
   `page`, `theme`, `menu`, `glossary`, `usageCounter`). There is no
   `wooBatch` and no `wooAssessment` schema anywhere in the repo, so
   `woo_register` / `woo_batch_schema` / `woo_assessment_schema` have nothing
   to point at.
2. **No auto-configuration.** `SettingsService::updateObjectTypeConfiguration()`
   populates `{slug}_register` / `{slug}_schema` for nine object types whose
   config-key prefix equals the schema slug, plus an explicit `$ooapiTypeMap`
   for the OOAPI keys whose prefix deliberately differs. The WOO keys match
   neither convention (prefix `woo_`, schema slugs `wooBatch` /
   `wooAssessment`, and a *shared* `woo_register`), so no code path ever sets
   them. `occ config:list opencatalogi` on a provisioned instance confirms all
   three keys are absent.
3. **Sentinels never resolve.** `ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS`
   lists 16 keys and omits the WOO ones, so `resolveManifestSentinelsSync()` in
   `src/main.js` finds no initial state, leaves `@resolve:woo_register`
   untouched by design ("unknown / unset keys are left untouched"), and the
   manifest page requests a literal sentinel as a register id.

This is the fleet's ORPHANED CAPABILITY defect class: spec-says-done ≠ feature
runs. `WooService::createBatch()` throws `RuntimeException('OpenRegister WOO
register/schema unavailable or unconfigured')` on the very first call, so no
municipality has ever been able to run a Woo-verzoek batch on a stock install
— which matters directly given the Woo procurement window this app is chasing.

## Scope
- Add `wooBatch` and `wooAssessment` schemas to the shared publication
  register (`lib/Settings/publication_register.json`), modelled exactly on the
  object shapes `WooService` reads and writes today — no new fields invented,
  no field the service writes omitted.
- Extend `SettingsService::updateObjectTypeConfiguration()` with an explicit
  WOO key map (mirroring the existing `$ooapiTypeMap` precedent) so
  `woo_register` (the shared publication register id), `woo_batch_schema` and
  `woo_assessment_schema` are populated on install/repair.
- Add the three WOO keys to `ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS`.
- **Anti-drift guard**: a unit test asserting that every `@resolve:<key>`
  sentinel appearing anywhere in the effective manifest (base + `manifest.d`
  fragments + menu layout) is present in `MANIFEST_CONFIG_KEYS`. This is the
  generic fix — the same drift silently broke this page and would break the
  next one added.

## Out of scope
- Any change to the WOO workflow logic itself (assessment, weigeringsgronden,
  inventarislijst, approval chain, Deck integration) — that code is correct,
  it simply had nowhere to store objects.
- `WooService::resolveStackId()` (returns 0; Deck stack mapping) and the
  silent degradation when the Deck leaf is absent — tracked separately.
- Retro-configuring already-installed instances beyond what the existing
  repair-step/`autoConfigure` path does on upgrade.

## Impact
- Touched: `lib/Settings/publication_register.json` (+2 schemas),
  `lib/Service/SettingsService.php` (WOO key map),
  `lib/Listener/ProvideManifestConfigStateListener.php` (+3 keys), unit tests.
- Specs: delta on `woo-transparency` (ADDED WOO-PROV-001..003).
- Behavioural: `/woo` renders a real (empty-but-live) batch list instead of a
  404-backed dead table; `WooService::createBatch()` stops throwing
  "unconfigured" on a stock install.
