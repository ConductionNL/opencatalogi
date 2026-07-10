# Proposal: beta-surface-alignment

## Summary
Reconcile OpenCatalogi's four public-facing surfaces — `appinfo/info.xml`, `src/manifest.json` navigation, the `conduction.nl/apps/opencatalogi` product page (EN + NL), and `docs/` (opencatalogi.conduction.nl) — so they describe the same, verified, shipped feature set ahead of a beta release. This is a documentation/metadata-only change; no application code was touched.

## Motivation
Fleet-wide beta-readiness audit (2026-07-07) found:
- `info.xml` shipped a single, language-less `<summary>` with a typo ("togethe") instead of the fleet-standard `lang="en"`/`lang="nl"` summary + description pair (see softwarecatalog/openregister for the established pattern).
- The EN product page asserted three integrations with **no corresponding code**: an XWiki wiki macro, a Mail/Files sidebar search, and an LLM "ask in plain language" semantic search. Only a minimal, read-only MCP tool provider (`lib/Mcp/OpenCatalogiToolProvider.php`) exists, exposing `searchCatalog` / `getPublication` to the Nextcloud AI Chat Companion — a much narrower capability than advertised.
- The EN and NL product pages both claimed "Eleven/Elf Woo-categorieën" — the actual code and `openspec/specs/woo-compliance/spec.md` (WOO-003) support **17** mandatory Woo information categories.
- The marketing "Federation status" dashboard-widget mockup (per-instance health dots) does not exist; the real shipped widgets are Catalogs, Most-viewed publications, Unpublished publications/attachments, and the Retention review queue.
- Product-page version/status (`v2.4`, "Stable") did not track `info.xml` (`0.7.41`) or the app's actual maturity; fleet convention (see pipelinq, docudesk, scholiq) is to derive the marketing version from `info.xml`, not manifest.json's internal schema version (2.5.0, unrelated).
- `docs/index.md`'s feature list and roadmap were stale (predated the current manifest nav, still listed dashboard widgets as "planned" though they ship today).

## Canonical feature vocabulary (source of truth: manifest.json nav + lib/Controller)
1. **Catalogs & Publications** — CatalogiController, PublicationsController; organize publications into catalogs with per-catalog access scope.
2. **Federated Directory** — DirectoryController + `Cron\DirectorySync`; discover/sync catalogues across Nextcloud instances.
3. **DCAT-AP & DIWOO export** — DcatController + WooController + SitemapController; DCAT-AP metadata harvest and DIWOO XML sitemaps for all 17 mandatory Woo information categories.
4. **Woo (Wet open overheid) compliance** — WooController, RobotsController, SitemapController; sitemap/robots.txt generation, audit trail.
5. **Retention lifecycle** — RetentionController + `Cron\RetentionEvaluation`; daily background evaluation, `RetentionWidget` review-queue dashboard widget.
6. **Content management** — GlossaryController, ThemesController, PagesController, MenusController.
7. **Organizations** — organization publisher profiles (OIN/TOOI/RSIN/PKI identifiers).
8. **AI Chat Companion (MCP)** — `lib/Mcp/OpenCatalogiToolProvider.php`; read-only `searchCatalog` / `getPublication` tools.
9. **Faceted / federated search** — SearchController; full-text search across registers and federated catalogues.
10. **Dashboard widgets** — CatalogiWidget, MostViewedPublicationsWidget, UnpublishedPublicationsWidget, UnpublishedAttachmentsWidget, RetentionWidget.

## Reconciliation — edits made
1. **`appinfo/info.xml`**: replaced the single language-less, typo'd `<summary>` and single `<description>` with `lang="en"` + `lang="nl"` summary and description pairs, mirroring the softwarecatalog/openregister template. Description now lists the canonical feature vocabulary above (was previously a generic 2019-era blurb).
2. **`conduction-website/src/pages/apps/opencatalogi.mdx`** (EN):
   - `version="v2.4"` → `version="v0.7"` (derived from `info.xml` 0.7.41), `status` `Stable` → `Beta`.
   - "Eleven Woo-categorieën" → "All 17 mandatory Woo information categories" (factual count fix; verified against `openspec/specs/woo-compliance/spec.md` WOO-003).
   - `WidgetShelf` mock widgets replaced with the actual shipped dashboard widgets (most-viewed publications, catalogues-at-a-glance, retention review queue) instead of an invented "Federation status" per-instance health panel.
   - Removed the entire `<Showcase>` block (XWiki wiki macro, Mail/Files sidebar, "ask in plain language" LLM search) — none of the three integrations exist in code. The NL page never had this section either, so removing it also restores EN/NL parity.
3. **`conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/opencatalogi.mdx`** (NL): same version/status fix; "Elf Woo-categorieën" → "Alle 17 verplichte Woo-informatiecategorieën"; fixed a stale `docs.conduction.nl/opencatalogi` docs link to the actual docs domain `opencatalogi.conduction.nl` (used consistently by `info.xml` and the EN page).
4. **`docs/index.md`**: rewrote "Key Features" to the canonical vocabulary above; replaced the stale "Q1 2025 New Dashboarding (Planned)" roadmap entry (dashboard widgets already ship) with an accurate "delivered" entry, and added 2026 Woo/DIWOO + AI Chat Companion milestones that were previously undocumented.

## Claims verified vs removed
| Claim | Verdict | Evidence |
|---|---|---|
| DCAT-AP export | Verified — kept | `lib/Controller/DcatController.php`, `DcatService`/`DcatSerializer` |
| Woo / DIWOO compliance, 17 categories | Verified, count corrected (was "eleven") | `openspec/specs/woo-compliance/spec.md` WOO-003, `WooController`, `SitemapController` |
| Federated directory sync | Verified — kept | `lib/Cron/DirectorySync.php`, `DirectoryController` |
| Retention lifecycle + dashboard widget | Verified — kept | `lib/Cron/RetentionEvaluation.php`, `src/views/widgets/RetentionWidget.vue` |
| AI Chat Companion / LLM support | Verified but overclaimed — narrowed | `lib/Mcp/OpenCatalogiToolProvider.php` exposes 2 read-only MCP tools, not open-ended "ask in plain language" semantic search |
| XWiki wiki-macro integration | **Removed — no code** | `grep -rli xwiki` across lib/src: zero hits |
| Mail/Files sidebar integration | **Removed — no code** | No `FilesPlugin`/`MailPlugin`/sidebar-search controller or component exists |
| "Federation status" per-instance health widget | **Removed / replaced** | No such widget registered in `src/manifest.json` or `src/*Widget.js`; real widgets listed above |
| Citation-stable URLs | Verified (generic, via OpenRegister immutable UUIDs) — kept, unchanged | OpenRegister object IDs are immutable by design; no bespoke opencatalogi citation feature, but the underlying claim holds |

## Icon status
`img/app.svg` is a white-fill-only path with no background rect (`fill="#ffffff"`), consistent with the fleet app-icon convention (transparent background, white glyph). No change needed. The product-page hero uses a separate, deliberately minimalist line-icon per app (fleet-wide design-system pattern in `@conduction/docusaurus-preset`, not a literal reuse of `img/app.svg`) — this is consistent with how every other app's product page is built, not an opencatalogi-specific mismatch, so no action taken.

## Out of scope / still misaligned (needs a decision)
- `src/manifest.json` menu/nav labels ("Catalogue", "WOO", "Administration") were treated as ground truth for the vocabulary above but were not themselves renamed — they already read cleanly and match the shipped controllers.
- `docs/GOVERNMENT-FEATURES.md` (Dutch overheid feature checklist) was already accurate and consistent with this canonical vocabulary; no changes were needed there.
- l10n/*.js frontend strings were **not** touched, per opencatalogi's own `CLAUDE.md` (dedicated tooling required); none of the edits in this change touch `t('opencatalogi', ...)`-wrapped strings, so no l10n follow-up is required.
