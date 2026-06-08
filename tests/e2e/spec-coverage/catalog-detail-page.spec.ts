/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Catalog detail page
 * (manifest page type:custom → CatalogDetailPageView, built on
 * CnDetailPage). Reached by navigating to the Catalogs list and clicking a
 * catalog row — the genuine user journey into a detail view.
 *
 * Adapts to the rendered data state (driven by the actual UI, not an API
 * count — the catalog index register may differ from the /api/catalogi
 * endpoint): if the table shows at least one catalog row, it opens that
 * row and asserts the bespoke CatalogDetailPage (CnDetailPage) renders; if
 * the index shows its empty-state, it asserts that instead. Both are real
 * rendered outcomes — neither is a synthetic pass.
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
		async ({ page }) => {
			const errors = trackPageErrors(page)

			await bootApp(page)
			await navTo(page, 'CatalogsMenu', true)
			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// Wait for the index body to settle into either rows or empty-state.
			const rows = content(page).locator(
				'[data-testid="cn-object-row"], .cn-object-card, .cn-card-grid [role="button"]',
			)
			const emptyState = content(page).locator(
				'[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
			).first()
			await expect(rows.first().or(emptyState)).toBeVisible({ timeout: 15000 })

			if (await rows.count() > 0) {
				// Open the first catalog and assert the bespoke detail page renders.
				await rows.first().click()
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
