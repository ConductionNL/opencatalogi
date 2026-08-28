# Design: fix-woo-capability-provisioning

## Approach
Provision, don't rewrite. `WooService` is correct code that was never given
storage; the fix is three small, surgical additions plus one generic guard.

## Decisions

### D1 — Schemas join the SHARED publication register, not a new one
`SettingsService`'s import pipeline creates exactly one register
(`publication`); the OOAPI change already documented that a genuinely separate
register is not achievable without rearchitecting `SettingsService`. `WooService`
also treats the register as shared (`getRegister()` backs both batch and
assessment objects). So `wooBatch`/`wooAssessment` are added to
`publication_register.json` and `woo_register` resolves to that same register id.

### D2 — Explicit key map, mirroring `$ooapiTypeMap`
The WOO keys break the `type === slug` convention twice: the prefix differs
(`woo_` vs `wooBatch`) and `woo_register` is a *register* key with no matching
schema slug. The existing `$ooapiTypeMap` is the established precedent for
exactly this; reuse the pattern rather than bending the slug convention.
`woo_register` is set from the resolved publication register id directly.

### D3 — Field inventory is derived from the service, not invented
Every property comes from reading `WooService`'s writes:
`createBatch()` (`caseReference`, `status`, `deckBoardId`, `deckAvailable`,
`documents`, `besluit`, `inventarislijst`, `createdAt`, `updatedAt`,
`createdBy`), the assessment literal (`documentReference`, `fileName`,
`fileType`, `assessment`, `weigeringsgronden`, `redactionInstructions`,
`anonymizedDocument`, `caseReference`, `assessedBy`, `assessedAt`), and the
later `$batch[...]` assignments (`documentSummary`, `publishedAt`,
`publishedCount`, `wooPublication`). Enum values likewise come from the
service's own literals. A test pins this correspondence so the schema cannot
drift from the writer.

### D4 — The parity test is the real fix
Adding three keys to a hand-maintained list fixes today's page; it does not
stop the next one from breaking the same way. The listener's own docblock
already claims the list is "kept in sync with the object types OpenCatalogi's
frontend registers" — it drifted anyway, silently, and the only symptom was a
404 in a console nobody reads. The parity test converts that silent runtime
failure into a loud build failure, and is the same anti-rot pattern shipped
for `openapi.json` in `public-api-openapi-document`.

Implementation: parse the effective manifest the way `src/main.js` does (base
`src/manifest.json` + `src/manifest.d/*.json` + `src/menu-layout.json`),
regex every `@resolve:([a-z][a-z0-9_-]*)` string, reflect
`MANIFEST_CONFIG_KEYS` off the listener class, and assert the sentinel set is
a subset. Reflection (not a duplicated literal list) keeps one source of truth.

### D5 — Idempotent, non-destructive configuration
Follow the existing loop's shape: only write a key when the corresponding
schema was actually found in the import result. Never write an empty string
over a value an operator set by hand.

## Verification
Beyond unit tests, the change is live-verified on the dev instance: run the
repair/auto-configure path, confirm `occ config:list opencatalogi` now shows
the three WOO keys, and confirm `/woo` no longer emits `@resolve:` 404s.
