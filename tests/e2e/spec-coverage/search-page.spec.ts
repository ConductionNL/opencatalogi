/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Search page (manifest page
 * type:"search", route /search), reached via the top-level "Search"
 * CnAppNav entry.
 *
 * KNOWN BUG — Search page renders blank (test.fixme below).
 *   The manifest declares the Search page as `type: "search"`, but the
 *   custom page type "search" is NOT registered in the page-type registry
 *   passed to CnAppRoot. `src/main.js` builds `pageTypes = { ...defaultPageTypes }`
 *   and `defaultPageTypes` only provides `index | detail | dashboard`.
 *   The app's own `src/views/search/SearchIndex.vue` exists but is never
 *   wired into `pageTypes` or the customComponents registry. At runtime
 *   CnPageRenderer.resolvedComponent() therefore returns `null` for the
 *   /search route (logging "[CnPageRenderer] Unknown page type 'search'…"),
 *   so the Search nav entry leads to an empty content area.
 *
 *   FIX (app source, out of scope for this test-only change): register the
 *   search page type, e.g. in src/main.js
 *     const pageTypesProp = { ...defaultPageTypes, search: SearchIndex, roadmap: ... }
 *   (or expose SearchIndex via the customComponents registry and set the
 *   manifest page to type:"custom" + component:"SearchIndex").
 *
 *   The assertions below describe the CORRECT expected behaviour. Once the
 *   page type is registered, drop the `test.fixme` to re-enable.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test search-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('search-page', () => {
	test.fixme(
		// @e2e search::search-page-renders-search-surface
		'Search — renders the search publications view with a search input',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'Search')

			// The search view heading (SearchIndex.vue: "Search publications").
			await expect(content(page).getByText(/Search publications/i).first())
				.toBeVisible({ timeout: 15000 })

			// A search input the user can interact with.
			const searchInput = content(page).locator(
				'input[type="search"], input[type="text"], [role="searchbox"], '
				+ 'input[placeholder*="earch"]',
			).first()
			await expect(searchInput).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
