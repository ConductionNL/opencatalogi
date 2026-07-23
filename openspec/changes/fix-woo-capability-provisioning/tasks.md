# Tasks: fix-woo-capability-provisioning

- [ ] Freeze delta spec `specs/woo-transparency/spec.md` (ADDED WOO-PROV-001..003); `openspec validate fix-woo-capability-provisioning` green
- [ ] Add `wooBatch` + `wooAssessment` schemas to `lib/Settings/publication_register.json`, field-for-field from `WooService`'s writes (D3); English `title`+`description` on every property; enums exactly as the service uses them
  - Spec ref: WOO-PROV-001
  - Acceptance: PHPUnit cross-checks the shipped register JSON against the field inventory; schema-property-titles + relation-dialect gates green
- [ ] Extend `SettingsService::updateObjectTypeConfiguration()` with the explicit WOO key map (`woo_register` ← publication register id, `woo_batch_schema` ← `wooBatch`, `woo_assessment_schema` ← `wooAssessment`), idempotent + never overwrite with empty
  - Spec ref: WOO-PROV-002
  - Acceptance: PHPUnit — all three keys set from a mocked import result; missing-schema case leaves the key untouched
- [ ] Add `woo_register`, `woo_batch_schema`, `woo_assessment_schema` to `ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS`
  - Spec ref: WOO-PROV-003
- [ ] Anti-drift parity test: every `@resolve:<key>` in the effective manifest (base + `manifest.d/*` + `menu-layout.json`) must be in `MANIFEST_CONFIG_KEYS`, read via reflection (single source of truth); prove by mutation that removing a key fails the test, then restore
  - Spec ref: WOO-PROV-003
  - Acceptance: test fails naming the offending key when a sentinel is unbacked
- [ ] Live-verify on the dev instance: run the auto-configure/repair path, `occ config:list opencatalogi` shows the three WOO keys, and `/woo` loads with no `@resolve:` 404 in the console
  - Spec ref: WOO-PROV-002, WOO-PROV-003
- [ ] `@spec` tags on changed methods; SPDX headers intact; full unit suite shows no new failures vs the documented baseline (SetupControllerTest ×2, DirectoryServiceTest ×1)
