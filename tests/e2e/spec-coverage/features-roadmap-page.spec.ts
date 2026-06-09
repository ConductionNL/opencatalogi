/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi "Features & roadmap" page
 * (manifest page type:"roadmap", route /features-roadmap), reached via its
 * footer-section CnAppNav entry.
 *
 * The manifest declares this page as `type: "roadmap"`. That page type is
 * provided by @conduction/nextcloud-vue's `defaultPageTypes`
 * (roadmap -> CnFeaturesAndRoadmapPage), which `src/main.js` spreads into
 * the `pageTypes` registry passed to CnAppRoot. CnPageRenderer mounts
 * CnFeaturesAndRoadmapPage (-> CnFeaturesAndRoadmapView) for the
 * /features-roadmap route. The view renders a "Features" header with a
 * "Show roadmap" toggle and a "Suggest feature" CTA.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test features-roadmap-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('features-roadmap-page', () => {
	test(
		// @e2e dashboard::features-roadmap-renders-content
		'Features & roadmap — renders roadmap content (toggle / suggest CTA)',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'FeaturesRoadmapMenu')

			// Genuine roadmap content rendered by CnFeaturesAndRoadmapView:
			// the "Show roadmap" view-toggle and the "Suggest feature" CTA.
			// Scoped to the router-view content so it cannot false-pass on
			// the navigation list.
			await expect(
				content(page).getByText(/show roadmap|suggest feature|roadmap/i).first(),
			).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
