/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Search page (manifest page
 * type:"search", route /search), reached via the top-level "Search"
 * CnAppNav entry.
 *
 * The manifest declares the Search page as `type: "search"`. That page
 * type is provided by @conduction/nextcloud-vue's `defaultPageTypes`
 * (search -> CnSearchPage), which `src/main.js` spreads into the
 * `pageTypes` registry passed to CnAppRoot. CnPageRenderer therefore
 * mounts CnSearchPage for the /search route. CnSearchPage renders the
 * page title (manifest `page.title` -> `title` prop), a `type="search"`
 * query input, and an idle hint ("Start typing to search.").
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test search-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('search-page', () => {
	test(
		// @e2e search::search-page-renders-search-surface
		'Search — renders the search surface with a search input',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'Search')

			// CnSearchPage mounts (type:"search" dispatched by CnPageRenderer).
			await expect(content(page).locator('[data-testid="cn-search-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// A search input the user can interact with.
			const searchInput = content(page).locator(
				'[data-testid="cn-search-page-input"], input[type="search"]',
			).first()
			await expect(searchInput).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
