/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for the OpenCatalogi Dashboard (manifest page
 * type:custom → DashboardView, built on CnDashboardPage). This is the
 * landing page after boot.
 *
 * Asserts the genuine dashboard content: the dashboard page shell, the
 * "Dashboard" title, the stat cards (Publications / Concept / Published /
 * Depublished), the two analytics sections (Publications by Category +
 * Activity), and the primary "New Publication" action. Then exercises a
 * real interaction: clicking "New Publication" opens the create modal.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test dashboard-page
 */
import { test, expect } from '@playwright/test'
import { bootApp, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('dashboard-page', () => {
	test(
		// @e2e dashboard::dashboard-renders-stats-and-activity
		'Dashboard — renders stat cards, category + activity sections and primary action',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)

			// Dashboard is the default route — its CnDashboardPage shell must mount.
			await expect(page.locator('[data-testid="cn-dashboard-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// The two analytics sections — distinctive Dashboard content that
			// confirms DashboardView (not a generic page) rendered.
			await expect(content(page).getByText(/Publications by Category/i).first())
				.toBeVisible({ timeout: 15000 })
			await expect(content(page).getByText(/Activity/i).first())
				.toBeVisible({ timeout: 15000 })

			// The publication stat cards.
			for (const label of [/Concept Publications/i, /Published/i, /Depublished/i]) {
				await expect(content(page).getByText(label).first()).toBeVisible({ timeout: 15000 })
			}

			// Primary action button.
			await expect(content(page).getByRole('button', { name: /New Publication/i }).first())
				.toBeVisible({ timeout: 10000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)

	test(
		// @e2e dashboard::new-publication-opens-create-modal
		'Dashboard — "New Publication" opens the create publication modal',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await expect(page.locator('[data-testid="cn-dashboard-page"]').first())
				.toBeVisible({ timeout: 15000 })

			const newPub = content(page).getByRole('button', { name: /New Publication/i }).first()
			await expect(newPub).toBeVisible({ timeout: 10000 })
			await newPub.click()

			// A create dialog/modal must appear (form dialog or generic modal).
			const modal = page.locator(
				'[data-testid-modal="cn-form-dialog"], [data-testid="cn-modal"], '
				+ '.modal-container, [role="dialog"]',
			).first()
			await expect(modal).toBeVisible({ timeout: 10000 })

			await page.keyboard.press('Escape')
			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
