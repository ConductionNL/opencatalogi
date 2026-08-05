/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for the OpenCatalogi Directory page, reached via
 * its CnAppNav entry (AdminGroup).
 *
 * REPAIRED: the page no longer renders the old CnFederationStatus surface
 * (`cn-federation-status` / `cn-federation-status-summary` testids no
 * longer exist in the deployed app) — it renders the federation directory
 * summary (`federation-directory-summary`: available/degraded/unreachable
 * counts, the listing cards, and the "Add directory" action). The previous
 * assertions targeted the removed testids and failed against the live app.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test directory-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('directory-page', () => {
	test(
		// @e2e federation::directory-renders-federation-status
		'Directory — renders the federation directory summary surface',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'DirectoryMenu')

			// The directory summary surface must mount (not the dashboard).
			await expect(page.locator('[data-testid="federation-directory-summary"]').first())
				.toBeVisible({ timeout: 15000 })

			// Its availability counters and the add-directory action render as
			// part of the surface.
			await expect(content(page).getByText(/available/i).first())
				.toBeVisible({ timeout: 15000 })
			await expect(content(page).getByRole('button', { name: /add directory/i }).first())
				.toBeVisible({ timeout: 10000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
