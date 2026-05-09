# Tasks — OpenCatalogi manifest v1: JSON manifest renderer adoption

## 1. Per-page mapping decision

- [x] 1.1 Walk every route in `src/router/index.js` (16 entries). Read each corresponding `src/views/<Page>.vue` to confirm whether it can map to a built-in page type or must stay `type: "custom"`.
- [x] 1.2 Decide each page's target type per `design.md`: 1 `index` (Directory) + 15 `custom` (everything else).
- [x] 1.3 Document the retention reason for each `type: "custom"` entry in `design.md`'s "Per-page mapping table".

## 2. Manifest authoring

- [x] 2.1 Create `src/manifest.json` with `version: "0.1.0"`, `dependencies: ["openregister"]`, `menu` mirroring `MainMenu.vue`, and `pages[]` covering every router entry.
- [x] 2.2 Each page entry uses the same `id` as the existing route name, the same `route` path, and a sensible `title`.
- [x] 2.3 The Directory page declares `type: "index"` with `config.register: "@resolve:listing_register"`, `config.schema: "@resolve:listing_schema"`, plus `columns[]` from the existing list view + `sidebar.enabled: true`.
- [x] 2.4 Every other page declares `type: "custom"` and a `component` field naming the registry export from `src/customComponents.js`.
- [x] 2.5 Top-level `$schema` references the canonical lib schema URL (raw GitHub).

## 3. Bootstrap rewrite

- [x] 3.1 Rewrite `src/main.js`:
  - Build vue-router from `manifest.pages[*].{ id, route }` via `routesFromManifest()`.
  - Shallow-clone `CnPageRenderer` before mounting per route (Vue.extend frozen-component fix).
  - Fire-and-forget `loadTranslations` (mount-survivable bootstrap).
  - Pass shallow-cloned `defaultPageTypes` + `customComponents` into `App` as props.
- [x] 3.2 Rewrite `src/App.vue`:
  - Mount `<CnAppRoot>` with `manifest`, `customComponents`, `pageTypes`, `appId="opencatalogi"`, `translateForApp`, `permissions` props.
  - Keep the `objectSidebarState` + `sidebarState` provide channels (`Vue.observable` reactive objects).
  - Keep `Modals` / `Dialogs` / `UserSettings` host components rendered alongside `<CnAppRoot>`.
  - Mirror decidesk's `#sidebar` slot pattern for `<CnObjectSidebar>` so `CnDetailPage` can drive it host-side.
- [x] 3.3 Create `src/customComponents.js` exporting all 15 retained custom views.
- [x] 3.4 Delete `src/router/index.js` (folded into `main.js`).
- [x] 3.5 Delete `src/navigation/MainMenu.vue` (replaced by lib's `CnAppNav`).

## 4. Dependency + build wiring

- [x] 4.1 Bump `package.json` `@conduction/nextcloud-vue` floor from `^0.1.0-beta.18` to `^1.0.0-beta.12`.
- [x] 4.2 Add `@nextcloud/axios$` exact-match alias to `webpack.config.js` (resolve to `node_modules/@nextcloud/axios/dist/index.js`). Sidesteps the lib's CJS bundle hitting the package's `exports`-field gate.
- [x] 4.3 Run `npm install` against the new lib floor; confirm `node_modules/@conduction/nextcloud-vue/package.json` reports a `1.0.0-beta.x` version.

## 5. l10n + appinfo

- [x] 5.1 Mirror `l10n/en.json` to `l10n/en_US.json` so the standard NC dev container's Apache rewrite serves the right locale instead of 404ing through to `index.php`.
- [x] 5.2 Bump `appinfo/info.xml` `<version>` from `0.7.33` to `0.7.34`.

## 6. Validator script

- [x] 6.1 Add `tests/validate-manifest.js` (mirror decidesk's). The script loads `src/manifest.json` and validates against the canonical lib schema using Ajv (with the `2020` draft entry point) and `ajv-formats`.
- [x] 6.2 Schema lookup falls back through env var → installed `node_modules` → sibling worktree → `/tmp/worktrees/nextcloud-vue-*` so the script works whether the lib is published or locally aliased.
- [x] 6.3 Run `node tests/validate-manifest.js`. Confirm zero schema errors.

## 7. Validation + build

- [x] 7.1 Run `npx eslint src/manifest.json src/main.js src/App.vue src/customComponents.js webpack.config.js tests/validate-manifest.js` (or the equivalent `npm run lint -- ...`). Confirm clean.
- [x] 7.2 Run `npx webpack --config webpack.config.js --mode production`. Confirm the build succeeds (no resolution errors, no compile errors).

## 8. Spec artifacts

- [x] 8.1 `openspec/changes/opencatalogi-manifest-v1/proposal.md` — covers the migration scope.
- [x] 8.2 `openspec/changes/opencatalogi-manifest-v1/design.md` — full per-page mapping + risks + bootstrap shape + cleanup follow-up.
- [x] 8.3 `openspec/changes/opencatalogi-manifest-v1/tasks.md` — this file.
- [x] 8.4 `openspec/changes/opencatalogi-manifest-v1/specs/opencatalogi-manifest-v1/spec.md` — Requirements REQ-OCMV1-1 through REQ-OCMV1-N covering the manifest contract.

## 9. Sign-off (per ADR-024 §9)

- [x] 9.1 `src/manifest.json` validates against the canonical schema (v1.2.0).
- [x] 9.2 `manifest.dependencies` is `["openregister"]`.
- [x] 9.3 Tier choice is explicit (Tier 4 — full `CnAppRoot` adoption).
- [x] 9.4 `manifest.version` is `"0.1.0"` (initial; bump to `1.0.0` once the heavy pages migrate to declarative built-ins).
- [x] 9.5 Custom-fallback inventory is documented and categorised in `design.md` (each retention has a one-line reason).
- [ ] 9.6 Browser regression smoke confirms every route still renders end-to-end. **Deferred to post-merge** — production app awaits human reviewer + manual QA before merge.
