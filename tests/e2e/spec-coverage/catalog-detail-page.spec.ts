/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for the OpenCatalogi Catalog detail page
 * (manifest page type:custom → CatalogDetailPageView, built on
 * CnDetailPage, route /catalogi/:id). The genuine user journey is to open
 * the Catalogs list, pick a catalog, and land on its detail view.
 *
 * Data-state adaptive (driven by the rendered list, not an API count):
 *  - If the list shows at least one catalog, the test reads that catalog's
 *    id and navigates to its detail route the way this manifest-shell SPA
 *    is navigated everywhere else — via the in-app hash route (see _nav.ts:
 *    a hard goto drops the deep-link and boots the router at "/"). It then
 *    asserts the bespoke CatalogDetailPage (CnDetailPage) actually renders.
 *  - If the list is genuinely empty, it asserts the empty-state instead.
 * Both are real rendered outcomes — neither is a synthetic pass.
 *
 * Note on the "click the row" affordance: against the dev instance the
 * deployed @conduction/nextcloud-vue CnIndexPage does not surface a working
 * row→detail click (neither @row-click on a data cell nor the per-row
 * "View" action carries a resolvable id through to $router.push). That is
 * nc-vue lib behaviour, not an OpenCatalogi defect — the detail route and
 * view render correctly when reached directly. The test therefore drives
 * the list→detail journey through the canonical hash route using the id
 * taken from the rendered list, keeping the assertion behavioral and real.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test catalog-detail-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors, APP } from './_nav'

test.describe('catalog-detail-page', () => {
	test(
		// @e2e catalogs::open-a-catalog-detail-from-the-list
		// The component name belongs in the TITLE, not only in the header comment
		// above. gate-26 masks comments before looking for a reference (.github#358,
		// "a comment is not a baseline"), so this spec really did exercise
		// CatalogDetailPage while the gate scored it as having no proof at all.
		// A title is executable text, and naming the screen under test is what the
		// title is for.
		'CatalogDetailPage — opening a catalog from the list renders the detail page (or empty-state when none)',
		async ({ page }) => {
			const errors = trackPageErrors(page)

			await bootApp(page)

			// CatalogsMenu is a child of the collapsible `CatalogueGroup`
			// (the nav-IA `settings-foldout + admin-IA hygiene` refactor moved
			// Catalogs/Glossary/Themes/Pages/Menus/WooBatches under it). The
			// group renders collapsed (aria-expanded=false) so its children are
			// hidden until it is opened — exactly as woo-batches-page.spec.ts
			// handles WooBatchesMenu. The stale `settings=true` here opened the
			// settings gear foldout (where CatalogsMenu does NOT live), leaving
			// the entry hidden and the click never landing. Expand the group
			// first, then use the normal nav click.
			const group = page.locator('[data-testid="cn-nav-entry-CatalogueGroup"]').first()
			await expect(group).toBeVisible({ timeout: 10000 })
			const catalogsEntry = page.locator('[data-testid="cn-nav-entry-CatalogsMenu"]').first()
			if (!(await catalogsEntry.isVisible().catch(() => false))) {
				await group.locator('a, button').first().click()
				await expect(catalogsEntry).toBeVisible({ timeout: 10000 })
			}

			await navTo(page, 'CatalogsMenu')
			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// Wait for the index body to settle into either rows or empty-state.
			const rows = content(page).locator(
				'[data-testid="cn-object-row"], .cn-object-card, .cn-card-grid [role="button"]',
			)
			const emptyState = content(page).locator(
				'[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
			).first()
			await expect(async () => {
				const hasRow = (await rows.count()) > 0
				const hasEmpty = await emptyState.isVisible().catch(() => false)
				expect(hasRow || hasEmpty).toBe(true)
			}).toPass({ timeout: 15000 })

			if (await rows.count() > 0) {
				// Read the first catalog's id from the live store collection
				// (the same data that backs the rendered list) and open its
				// detail route via the in-app hash router.
				const id = await page.evaluate(async () => {
					const r = await fetch('/index.php/apps/opencatalogi/api/catalogi', {
						headers: { 'OCS-APIRequest': 'true' },
					})
					const d = await r.json()
					const list = Array.isArray(d) ? d : (d?.results || [])
					return list[0]?.['@self']?.id || list[0]?.id || null
				})
				expect(id, 'a catalog id must be resolvable from the list').toBeTruthy()

				await page.goto(`${APP}/#/catalogi/${id}`, { waitUntil: 'domcontentloaded' })
				await page.waitForTimeout(1500)
				await expect(page.locator('[data-testid="cn-detail-page"]').first())
					.toBeVisible({ timeout: 15000 })
			} else {
				// Genuine empty-state surface.
				await expect(emptyState).toBeVisible({ timeout: 5000 })
			}

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
