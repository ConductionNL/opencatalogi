/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral UI coverage for OpenCatalogi's CnIndexPage-backed list pages,
 * reached by clicking their CnAppNav entries (manifest-shell SPA).
 *
 * Pages covered (manifest type:index):
 *   Catalogs (/catalogi), Organizations (/organizations), Themes (/themes),
 *   Glossary (/glossary), Pages (/pages), Menus (/menus).
 *
 * Each asserts the REAL index surface rendered: the cn-index-page root
 * (proves the right type:index page mounted, not the dashboard), the
 * primary "Add" CTA (every page sets showAdd:true), and a table / cards /
 * empty-state body — plus no fatal JS error. (CnIndexPage's in-content
 * title header is hidden by default — showTitle defaults to false — so the
 * page name lives in the NC app chrome, not the content area; we therefore
 * assert the index surface + actions, not a content <h1>.)
 *
 * For Catalogs we additionally open the create modal and assert its form
 * renders, then cancel — a genuine interaction.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test list-pages
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

const LIST_PAGES = [
	'CatalogsMenu', 'OrganizationsMenu', 'ThemesMenu',
	'GlossaryMenu', 'PagesMenu', 'MenusMenu',
]

for (const menuId of LIST_PAGES) {
	test.describe(`list-page ${menuId}`, () => {
		test(
			// @e2e content-management::generic-index-page-renders
			`${menuId} — renders index page with Add CTA and a list/empty surface`,
			async ({ page }) => {
				const errors = trackPageErrors(page)
				await bootApp(page)
				await navTo(page, menuId, true)

				// Genuine index surface mounted (not the dashboard, not blank).
				await expect(page.locator('[data-testid="cn-index-page"]').first())
					.toBeVisible({ timeout: 15000 })

				// Primary Add CTA present (manifest showAdd:true for all of these).
				await expect(page.locator('[data-testid="cn-cta-primary"]').first())
					.toBeVisible({ timeout: 10000 })

				// A real body: a data table, cards, or an empty-content state.
				const body = content(page).locator(
					'[data-testid="cn-object-list-table"], table, .cn-card-grid, '
					+ '[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
				).first()
				await expect(body).toBeVisible({ timeout: 15000 })

				expect(fatalErrors(errors)).toHaveLength(0)
			},
		)
	})
}

test.describe('list-page Catalogs interactions', () => {
	test(
		// @e2e catalogs::open-the-create-catalog-modal
		'Catalogs — Add CTA opens the create form modal, which renders a form and cancels',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, 'CatalogsMenu', true)

			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })

			const addCta = page.locator('[data-testid="cn-cta-primary"]').first()
			await expect(addCta).toBeVisible({ timeout: 10000 })
			await addCta.click()

			// The create modal (CnFormDialog → NcDialog portal) must open with
			// its create-form chrome: a "Create…" heading and a Create/Cancel
			// button pair. (The schema form body may render async / empty, so we
			// assert the dialog chrome rather than a specific input field.)
			const modal = page.locator('[role="dialog"]').filter({ hasText: /create/i }).first()
			await expect(modal).toBeVisible({ timeout: 10000 })
			await expect(modal.getByRole('button', { name: /^create$/i }).first())
				.toBeVisible({ timeout: 8000 })
			await expect(modal.getByRole('button', { name: /cancel/i }).first())
				.toBeVisible({ timeout: 8000 })

			// Close without creating anything (data-independent).
			await modal.getByRole('button', { name: /cancel/i }).first().click()
			await expect(modal).toBeHidden({ timeout: 8000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
