/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Catalog detail page
 * (manifest page type:custom → CatalogDetailPageView, built on
 * CnDetailPage). Reached by navigating to the Catalogs list and clicking a
 * catalog row — the genuine user journey into a detail view.
 *
 * Adapts to data state: if at least one catalog exists, it opens the
 * detail page and asserts the CnDetailPage surface; if the list is empty
 * it asserts the empty-state instead. Both are real rendered outcomes —
 * neither is a synthetic pass.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test catalog-detail-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('catalog-detail-page', () => {
	test(
		// @e2e catalogs::open-a-catalog-detail-from-the-list
		'Catalog detail — clicking a catalog row opens the detail page (or empty-state when none)',
		async ({ page, request }) => {
			const errors = trackPageErrors(page)

			// Determine data state up front so the assertion path is deterministic.
			const listResp = await request.get('/index.php/apps/opencatalogi/api/catalogi')
			const listBody = listResp.ok() ? await listResp.json().catch(() => null) : null
			const catalogs = Array.isArray(listBody?.results) ? listBody.results : []

			await bootApp(page)
			await navTo(page, 'CatalogsMenu', true)
			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })

			if (catalogs.length > 0) {
				// Open the first catalog — try a table row, then a card.
				const row = content(page).locator(
					'[data-testid="cn-object-row"], .cn-object-card, .cn-card-grid a, '
					+ '.cn-card-grid [role="button"]',
				).first()
				await expect(row).toBeVisible({ timeout: 15000 })
				await row.click()
				await page.waitForTimeout(1500)

				// The bespoke CatalogDetailPage (CnDetailPage) must render.
				await expect(page.locator('[data-testid="cn-detail-page"]').first())
					.toBeVisible({ timeout: 15000 })
			} else {
				// Genuine empty-state surface.
				await expect(content(page).locator('.empty-content, [class*="empty-content"]').first())
					.toBeVisible({ timeout: 15000 })
			}

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
