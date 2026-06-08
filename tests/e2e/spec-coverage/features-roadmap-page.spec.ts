/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi "Features & roadmap" page
 * (manifest page type:"roadmap", route /features-roadmap), reached via its
 * footer-section CnAppNav entry.
 *
 * KNOWN BUG — Features & roadmap page renders no page content
 * (test.fixme below). Same root cause as the Search page: the manifest
 * declares this page as `type: "roadmap"`, but the "roadmap" page type is
 * NOT registered in the page-type registry. `src/main.js` builds
 * `pageTypes = { ...defaultPageTypes }`, and `defaultPageTypes` only ships
 * `index | detail | dashboard`. There is no roadmap component anywhere in
 * the app (no src view, no registry entry), so CnPageRenderer resolves the
 * route to `null` and the content area is empty (CnPageRenderer logs
 * "[CnPageRenderer] Unknown page type 'roadmap'…").
 *
 *   FIX (app source, out of scope for this test-only change): register a
 *   roadmap page type / component and wire it into `pageTypes` (or convert
 *   the manifest page to type:"custom" with a registered component).
 *
 *   The assertion below requires genuine roadmap content (a roadmap
 *   heading/section). It deliberately scopes to the router-view content
 *   (`.app-content`) and excludes the navigation list so it cannot
 *   false-pass on nav <li> items. Drop `test.fixme` once the page type is
 *   registered.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test features-roadmap-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('features-roadmap-page', () => {
	test.fixme(
		// @e2e dashboard::features-roadmap-renders-content
		'Features & roadmap — renders roadmap content (heading / feature list)',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'FeaturesRoadmapMenu')

			// Genuine roadmap content: a heading or list that names features /
			// roadmap — NOT the navigation entries (which also live as <li> in
			// the shell). Match on roadmap-specific copy.
			await expect(
				content(page).getByText(/roadmap|feature request|upcoming|planned|in progress/i).first(),
			).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
