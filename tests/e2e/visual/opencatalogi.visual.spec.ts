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
 * dashboard baseline. This version resolves a real catalog slug via the API
 * and navigates the genuine hash route, asserting the publications index
 * actually rendered before shooting. The stale publications-visual-linux.png
 * baseline (a dashboard duplicate) was deleted so it regenerates honestly.
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test, expect } from '@playwright/test'
import {
	shootSurface,
	dismissSupportDialog,
	waitForContentReady,
	freezePage,
	dynamicMasks,
	SHOT_OPTIONS,
} from './_visual-helpers'

const APP = '/index.php/apps/opencatalogi'

test.describe('OpenCatalogi — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})

	test('publications list', async ({ page, request }) => {
		// Resolve a real catalog slug — the Publications index route requires one.
		const listResp = await request.get(`${APP}/api/catalogi`)
		expect(listResp.status(), 'GET /api/catalogi must succeed').toBe(200)
		const body = await listResp.json()
		const results: Array<Record<string, any>> = Array.isArray(body) ? body : (body?.results ?? [])
		const withSlug = results.find((c) => c?.slug || c?.['@self']?.slug)
		expect(withSlug, 'at least one catalog with a slug must exist for the publications baseline').toBeTruthy()
		const slug = String(withSlug!.slug ?? withSlug!['@self']?.slug)

		// Boot the SPA, then take the in-app hash route (path-form gotos boot
		// the Dashboard in this hash-mode SPA; see tests/e2e/spec-coverage/_nav.ts).
		await page.goto(`${APP}/#/`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		await waitForContentReady(page)
		await page.goto(`${APP}/#/publications/${slug}`, { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(1500)
		await dismissSupportDialog(page)
		await waitForContentReady(page)

		// Prove the publications index rendered (not the dashboard fallback)
		// before committing a baseline of it.
		await expect(page.locator('[data-testid="cn-index-page"]').first())
			.toBeVisible({ timeout: 15000 })

		await freezePage(page)
		await expect(page).toHaveScreenshot('publications.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
