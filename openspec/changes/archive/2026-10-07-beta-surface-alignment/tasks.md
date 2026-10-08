# Tasks: beta-surface-alignment

- [x] 1. Audit `appinfo/info.xml`, `src/manifest.json` nav, `lib/Controller/*`, product pages (EN+NL), and `docs/` for feature-vocabulary drift.
- [x] 2. Fix `appinfo/info.xml`: add `lang="en"`/`lang="nl"` `<name>`, `<summary>`, `<description>` (was a single language-less, typo'd summary); rewrite description to the canonical feature vocabulary.
- [x] 3. Fix EN product page (`conduction-website/src/pages/apps/opencatalogi.mdx`): version/status derived from `info.xml`; correct Woo-category count (11 → 17); replace fabricated `WidgetShelf` mock widgets with the real shipped dashboard widgets; remove the fabricated `<Showcase>` block (XWiki / Mail-Files / LLM-semantic-search — none implemented).
- [x] 4. Fix NL product page (`i18n/nl/.../opencatalogi.mdx`): same version/status/Woo-count fixes; correct stale docs URL.
- [x] 5. Fix `docs/index.md`: rewrite Key Features to canonical vocabulary; correct stale "planned" dashboard roadmap entry (already shipped) and add missing 2026 Woo/DIWOO + AI milestones.
- [x] 6. Verify `img/app.svg` against the fleet app-icon convention (white-fill, transparent background) — no change needed.
- [x] 7. Confirm no `l10n/*.js` files were touched (per opencatalogi's dedicated l10n-tooling `CLAUDE.md`) — none of the edits touch `t('opencatalogi', ...)`-wrapped UI strings.
- [x] 8. Document canonical feature list, reconciliation, and verified/removed claims in `proposal.md`.
- [x] 9. Write this change's spec delta (`specs/beta-alignment/spec.md`).
