/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for OpenCatalogi's CnIndexPage-backed list pages.
 *
 * Pages covered (manifest type:index):
 *   Catalogs (/catalogi), Organizations (/organizations), Themes (/themes),
 *   Glossary (/glossary), Pages (/pages), Menus (/menus).
 *
 * Catalogs is reached by clicking its CnAppNav entry (manifest-shell SPA).
 * The other five no longer HAVE a nav entry — `src/menu-layout.json#removals`
 * retired them because the concepts belong to OpenRegister and Portaliq — so
 * they are reached by hash route, which is what `removals` guarantees stays
 * working. Same assertions either way.
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
import { expect, test } from '@playwright/test'
import {
	bootApp,
	content,
	fatalErrors,
	navTo,
	navToRoute,
	trackPageErrors,
} from './_nav'

/**
 * The index pages still reachable by clicking a nav entry.
 *
 * Catalogs is the only one left: it is what this app is for.
 */
const NAV_LIST_PAGES = ['CatalogsMenu']

/**
 * The index pages retired from the NAV but still routable.
 *
 * `src/menu-layout.json#removals` dropped these five menu entries because the
 * concepts belong elsewhere — Organizations to OpenRegister, and Glossary /
 * Themes / Pages / Menus to Portaliq (ADR-086), which already ships all four.
 * `removals` drops the entry and keeps the PAGE registered, which is the whole
 * point of the mechanism: stored objects, deep links and this coverage keep
 * working. So these assert exactly the same index surface as before — they
 * just arrive by route instead of by click.
 */
const ROUTE_LIST_PAGES: Array<{ id: string, route: string }> = [
	{ id: 'Organizations', route: '/organizations' },
	{ id: 'Themes', route: '/themes' },
	{ id: 'Glossary', route: '/glossary' },
	{ id: 'Pages', route: '/pages' },
	{ id: 'Menus', route: '/menus' },
]

for (const menuId of NAV_LIST_PAGES) {
	test.describe(`list-page ${menuId}`, () => {
		test(// @e2e content-management::generic-index-page-renders
		`${menuId} — renders index page with Add CTA and a list/empty surface`, async ({
			page,
		}) => {
			const errors = trackPageErrors(page)
			await bootApp(page)
			await navTo(page, menuId, true)

			// Genuine index surface mounted (not the dashboard, not blank).
			await expect(
				page.locator('[data-testid="cn-index-page"]').first(),
			).toBeVisible({ timeout: 15000 })

			// Primary Add CTA present (manifest showAdd:true for all of these).
			await expect(
				page.locator('[data-testid="cn-cta-primary"]').first(),
			).toBeVisible({ timeout: 10000 })

			// A real body: a data table, cards, or an empty-content state.
			const body = content(page)
				.locator(
					'[data-testid="cn-object-list-table"], table, .cn-card-grid, '
						+ '[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
				)
				.first()
			await expect(body).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		})
	})
}

for (const { id, route } of ROUTE_LIST_PAGES) {
	test.describe(`list-page ${id} (route-only)`, () => {
		test(// @e2e content-management::generic-index-page-renders
		`${id} — still renders its index page at ${route} after the nav entry was retired`, async ({
			page,
		}) => {
			const errors = trackPageErrors(page)
			await navToRoute(page, route)

			// The concept left the NAV, not the ROUTER. If `removals` ever
			// starts dropping the page too, this is where it shows up: the
			// router falls through to the Dashboard and no index page mounts.
			await expect(
				page.locator('[data-testid="cn-index-page"]').first(),
			).toBeVisible({ timeout: 15000 })

			// Its nav entry is genuinely gone — asserted here rather than
			// assumed, because a `removals` entry naming an id that does not
			// exist is a silent no-op.
			await expect(
				page.locator(`[data-testid="cn-nav-entry-${id}Menu"]`),
			).toHaveCount(0)

			// Primary Add CTA present (manifest showAdd:true for all of these).
			await expect(
				page.locator('[data-testid="cn-cta-primary"]').first(),
			).toBeVisible({ timeout: 10000 })

			// A real body: a data table, cards, or an empty-content state.
			const body = content(page)
				.locator(
					'[data-testid="cn-object-list-table"], table, .cn-card-grid, '
						+ '[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
				)
				.first()
			await expect(body).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		})
	})
}

test.describe('list-page Catalogs interactions', () => {
	test(// @e2e catalogs::open-the-create-catalog-modal
	'Catalogs — Add CTA opens the create form modal, which renders a form and cancels', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navTo(page, 'CatalogsMenu', true)

		await expect(
			page.locator('[data-testid="cn-index-page"]').first(),
		).toBeVisible({ timeout: 15000 })

		const addCta = page.locator('[data-testid="cn-cta-primary"]').first()
		await expect(addCta).toBeVisible({ timeout: 10000 })
		await addCta.click()

		// The create modal (CnFormDialog → NcDialog portal) must open with its
		// create-form chrome: the heading and a submit/cancel button pair.
		// (The schema form body may render async / empty, so we assert the
		// dialog chrome rather than a specific input field.)
		//
		// CatalogModal names itself "Add Catalog" and labels its submit button
		// "Add" — never "Create". The previous `/create/i` filter therefore
		// matched NO dialog and failed with "element(s) not found" even though
		// the modal opened correctly, on both the Vue 2 and Vue 3 builds.
		const modal = page
			.locator('[role="dialog"]')
			.filter({ hasText: /add catalog/i })
			.first()
		await expect(modal).toBeVisible({ timeout: 10000 })
		await expect(
			modal.getByRole('button', { name: /^add$/i }).first(),
		).toBeVisible({ timeout: 8000 })
		await expect(
			modal.getByRole('button', { name: /cancel/i }).first(),
		).toBeVisible({ timeout: 8000 })

		// Close without creating anything (data-independent).
		await modal
			.getByRole('button', { name: /cancel/i })
			.first()
			.click()
		await expect(modal).toBeHidden({ timeout: 8000 })

		expect(fatalErrors(errors)).toHaveLength(0)
	})
})
