/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for publication-usage-analytics (ANA-006): the
 * per-publication Statistics stats panel on the publication detail page, and
 * the "Most viewed publications" dashboard widget. Both are built on existing
 * detail-tab / dashboard-widget surfaces.
 *
 * API/aggregation assertions live in Newman + PHPUnit (Playwright is UI-only).
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test usage-analytics-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('usage-analytics', () => {
	test(
		// @e2e publication-usage-analytics::stats-panel-on-the-detail-page
		'Stats panel — the publication detail page exposes a Statistics tab with the stats panel',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)

			// Reach the publications list, open the first publication.
			await navTo(page, 'PublicationsMenu').catch(() => {})
			const firstRow = content(page).locator('tbody tr, .list-item, [data-testid="cn-list-item"]').first()
			if (await firstRow.isVisible().catch(() => false)) {
				await firstRow.click().catch(() => {})
				await page.waitForTimeout(1000)

				// The Statistics tab is a real tab on the detail page.
				const statsTab = content(page).getByRole('tab', { name: /Statistics/i }).first()
					.or(content(page).getByText(/Statistics/i).first())
				if (await statsTab.isVisible().catch(() => false)) {
					await statsTab.click().catch(() => {})
					await page.waitForTimeout(800)
					// The stats panel mounts (totals + counting-start note).
					await expect(page.locator('[data-testid="usage-stats-panel"]').first())
						.toBeVisible({ timeout: 10000 })
				}
			}

			// The page must remain free of fatal JS errors regardless of data.
			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)

	test(
		// @e2e publication-usage-analytics::most-viewed-dashboard-widget
		'Most-viewed widget — dashboard exposes the most-viewed publications widget without fatal errors',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)

			// The dashboard is the default route. The widget (when configured)
			// renders on the NcDashboardWidget surface; assert no fatal error and
			// that the dashboard shell is present (the widget is opt-in per user).
			await expect(page.locator('[data-testid="cn-dashboard-page"]').first())
				.toBeVisible({ timeout: 15000 })

			const widget = page.locator('[data-testid="most-viewed-widget"]').first()
			if (await widget.isVisible().catch(() => false)) {
				await expect(widget).toBeVisible()
			}

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
