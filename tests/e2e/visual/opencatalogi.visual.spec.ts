/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for OpenCatalogi's key surfaces (GAP-5).
 *
 * HONESTY NOTE (repaired suite): the previous 'publications list' test used
 * `shootByNav(page, appRoot, 'Publications', ...)` — but no nav entry
 * labelled "Publications" exists (publications live at
 * /publications/:catalogSlug, reached from a catalog). shootByNav silently
 * fell back to shooting "wherever we land", producing a second byte-identical
 * dashboard baseline. This version resolves a catalog scoped to the
 * publication register + schema via the API and navigates the genuine hash route, asserting the publications index
 * actually rendered before shooting. The stale publications-visual-linux.png
 * baseline (a dashboard duplicate) was deleted so it regenerates honestly.
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { expect, test } from '@playwright/test'
import { publicationCatalog } from '../publication-catalog.ts'
import {
	dismissSupportDialog,
	dynamicMasks,
	freezePage,
	shootSurface,
	SHOT_OPTIONS,
	waitForContentReady,
} from './_visual-helpers.ts'

const APP = '/index.php/apps/opencatalogi'

test.describe('OpenCatalogi — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/`, 'dashboard.png')
	})

	test('publications list', async ({ page, request }) => {
		// The Publications index route needs a catalog scoped to the publication
		// register + schema; any other catalog shows an empty state instead. A
		// fixed seed prefix keeps a seeded catalog's title stable for the baseline.
		const { path } = await publicationCatalog(request, 'visual-baseline')

		// Boot the SPA, then take the in-app hash route (path-form gotos boot
		// the Dashboard in this hash-mode SPA; see tests/e2e/spec-coverage/_nav.ts).
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		await waitForContentReady(page)
		await page.goto(`${APP}${path}`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForTimeout(1500)
		await dismissSupportDialog(page)
		await waitForContentReady(page)

		// Prove the publications index rendered (not the dashboard fallback)
		// before committing a baseline of it.
		await expect(
			page.locator('[data-testid="cn-index-page"]').first(),
		).toBeVisible({ timeout: 15000 })

		await freezePage(page)
		await expect(page).toHaveScreenshot('publications.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
