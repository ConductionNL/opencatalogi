# Retrofit — admin-settings (frontend)

## Why
The admin-settings backend is already specified by SET-001..014 (Bucket 1), but the
**frontend** settings surface (admin settings page, its bundle entry-point, and the user
settings placeholder dialog) had no spec coverage. This reverse-spec retroactively
documents that observed behavior.

## What Changes
Adds 3 ADDED requirements (SET-015..017) to the `admin-settings` capability and annotates
the implementing frontend code units with `@spec` tags. No code behavior changes.

## Affected code units
- src/views/settings/Settings.vue (admin settings page) (SET-015)
- src/settings.js (admin bundle entry-point) (SET-016)
- src/views/settings/UserSettings.vue (user settings placeholder dialog) (SET-017)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Observed note
`UserSettings.vue` is a literal placeholder ("User preferences will appear here.") — it
holds no real user preferences yet. SET-017 records this observed state; the spec
previously referenced only the Admin surface.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
