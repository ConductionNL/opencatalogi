/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Directory page (manifest
 * page type:custom → CnFederationStatus). Reached via its settings-section
 * CnAppNav entry.
 *
 * Asserts the genuine federation-status surface renders (cn-federation-
 * status root + its summary block) with no fatal JS error.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test directory-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('directory-page', () => {
	test(
		// @e2e federation::directory-renders-federation-status
		'Directory — renders the federation-status surface',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'DirectoryMenu', true)

			// The custom CnFederationStatus component must mount (not the dashboard).
			const fed = page.locator('[data-testid="cn-federation-status"]').first()
			await expect(fed).toBeVisible({ timeout: 15000 })

			// Its summary block (node counts) renders as part of the surface.
			const surface = content(page).locator(
				'[data-testid="cn-federation-status-summary"], .cn-federation-status',
			).first()
			await expect(surface).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
