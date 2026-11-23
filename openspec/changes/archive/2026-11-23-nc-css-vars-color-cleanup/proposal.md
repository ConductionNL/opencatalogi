---
kind: code
depends_on: []
---

# Proposal: nc-css-vars-color-cleanup

## Why

The fleet rule (ADR-004 frontend, ADR-010 NL Design) is: no hardcoded colors —
use Nextcloud CSS variables so nldesign theming and dark mode work. At HEAD,
`src/` contains 107 hardcoded hex color literals across 10 files (grep over
`*.vue` at HEAD):

- **Chart palettes**: `src/views/dashboard/Dashboard.vue:37` hardcodes a
  7-color donut palette (`['#0082C9', '#059669', '#D97706', '#DC2626',
  '#7C3AED', '#0891B2', '#DB2777']`) and `Dashboard.vue:145` hardcodes
  `['#079cff']` for the traffic chart. These do not follow the NC theme or
  nldesign overrides and are invisible-on-dark risks.
- **Light-only text colors**: `src/views/publications/PublicationList.vue:257`
  and `src/views/publications/PublicationDetail.vue:1159` force
  `color: #EBEBEB !important` — near-white text hardcoded regardless of
  theme.
- **A duplicated JSON syntax-highlight palette**: the same block of hardcoded
  token colors (`#448c27`, `#88c379`, `#221199`, `#770088`, `#d19a66`,
  `#d7eaff`, …) is copy-pasted in full into
  `src/modals/object/DownloadObject.vue:158-243`,
  `src/modals/menuItem/MenuItemForm.vue:1154-1216`,
  `src/modals/object/ObjectModal.vue` and
  `src/modals/object/UploadObject.vue`, with partial copies of the same
  palette in `src/modals/object/MergeObject.vue:1084-1129` and
  `src/modals/object/MigrationObject.vue`; `src/modals/generic/UploadFiles.vue:1037`
  additionally hardcodes `color: #ff0000 !important` where `--color-error`
  belongs. Besides violating the color rule, the duplication means the copies
  drift independently.

## What Changes

- Replace the dashboard chart palettes with a shared, theme-aware token list
  built from NC CSS variables (`--color-primary-element`, `--color-success`,
  `--color-warning`, `--color-error`, `--color-info` + derived shades),
  resolved at runtime (ApexCharts needs concrete values — read
  `getComputedStyle(document.documentElement)` once, not literals).
- Replace the `#EBEBEB !important` text colors in `PublicationList.vue` /
  `PublicationDetail.vue` with the appropriate NC variable
  (`--color-primary-element-text` or `--color-main-text` per context) and
  drop the `!important` where possible.
- Extract the JSON syntax-highlight styles into one shared stylesheet (e.g.
  `src/css/json-highlight.css` or a scoped shared component), mapped to NC
  variables with sensible fallbacks, and import it from the seven modals —
  deleting the copy-pasted blocks.
- Add a stylelint rule (the repo already ships `stylelint.config.js`) to
  reject new raw hex colors in `src/**/*.vue`, with an explicit allow-list
  only where a third-party lib demands literals.
