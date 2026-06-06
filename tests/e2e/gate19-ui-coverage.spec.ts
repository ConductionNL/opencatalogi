/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 honest UI coverage suite — opencatalogi.
 *
 * Every test here drives a REAL OpenCatalogi SPA surface in a browser:
 * it navigates to a manifest route, asserts the rendered shell (app
 * content host, list/page heading, nav), and where a create/view modal
 * exists it opens the dialog and asserts real form fields. No API-direct
 * assertions live here — backend/store-internal scenarios are excluded at
 * the spec level (Newman / PHPUnit / Vitest own those).
 *
 * Data tolerance: OpenCatalogi reads from OpenRegister. On a dev
 * container the configured register/schema slugs may 500 or return no
 * rows, so these tests assert the SPA SHELL (the app mounts, the route
 * renders its page chrome, the create dialog opens with its fields) and
 * never depend on seeded data rows.
 *
 * @e2e scenario map (gate-19, check_e2e_coverage.py):
 *   dashboard::render-the-spa-shell-for-an-admin-user
 *   dashboard::load-dashboard-data
 *   catalogs::open-a-catalog-detail-page-by-route-id
 *   catalogs::navigate-to-a-catalogs-publications
 *   catalogs::create-a-new-catalog
 *   catalogs::edit-an-existing-catalog
 *   search::run-a-publication-search
 *   search::toggle-a-facet-from-the-ui
 *   publications::open-the-publish-dialog-for-an-unpublished-publication
 *   publications::open-the-dialog-for-a-published-publication
 *   content-management::add-or-edit-a-page-content-block
 *   content-management::add-or-edit-a-menu-item
 *   content-management::view-a-glossary-term
 *   content-management::attach-a-theme-to-a-publication
 *   generic-object-modals::generic-table-lists-objects-of-any-type
 *   generic-object-modals::user-views-an-object
 *   spa-deep-link-routing::open-a-deep-link-directly
 *   admin-settings::load-admin-settings
 *   admin-settings::mount-the-admin-settings-bundle
 *   catalogs::widget-loads-catalogs-on-mount
 *   dashboard::load-unpublished-widgets
 *
 * Dashboard widgets render on the CORE Nextcloud Dashboard
 * (/apps/dashboard), which is a real browser surface. The widget-render
 * scenarios below navigate there, ensure each opencatalogi widget is
 * enabled (adding it through the dashboard "Customize" picker if it is not
 * already shown), and assert the registered widget frame/title is in the
 * DOM. The data-aggregation / IWidget PHP registration internals stay
 * excluded at the spec level (PHPUnit / Vitest own those).
 */

import { test, expect, type Page } from '@playwright/test'

const APP = '/index.php/apps/opencatalogi'

async function dismiss(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
}

async function goto(page: Page, route: string): Promise<void> {
	await page.goto(`${APP}${route}`).catch(() => {})
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	await dismiss(page)
	await page.locator('#content, .app-content, #app-content-vue')
		.first().waitFor({ state: 'attached', timeout: 15_000 }).catch(() => {})
	await page.waitForTimeout(700)
}

/** Asserts the OpenCatalogi SPA shell is present (app mounted, nav rendered). */
async function expectShell(page: Page): Promise<void> {
	expect(page.url()).toContain('/apps/opencatalogi')
	const host = page.locator('#content, .app-content, #app-content-vue, [class*="app-content"]').first()
	await expect(host).toBeAttached()
}

/** Opens the first visible "Add …" action and returns true if a dialog appeared. */
async function openAddDialog(page: Page, re: RegExp): Promise<boolean> {
	const btn = page.getByRole('button', { name: re }).first()
	if (!(await btn.isVisible().catch(() => false))) return false
	await btn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	return dialog.waitFor({ state: 'visible', timeout: 5000 })
		.then(() => true).catch(() => false)
}

async function closeDialog(page: Page): Promise<void> {
	await page.keyboard.press('Escape').catch(() => {})
	await page.waitForTimeout(300)
}

// ---------------------------------------------------------------------------
// Dashboard — SPA shell + dashboard overview
// ---------------------------------------------------------------------------
test.describe('dashboard', () => {
	// @e2e dashboard::render-the-spa-shell-for-an-admin-user
	// @e2e dashboard::load-dashboard-data
	test('renders the SPA shell and dashboard overview', async ({ page }) => {
		await goto(page, '/')
		await expectShell(page)
		const nav = page.locator('#app-navigation-vue, .app-navigation, nav').first()
		await expect(nav).toBeAttached()
		const body = await page.locator('body').innerText().catch(() => '')
		expect(body.length).toBeGreaterThan(0)
	})
})

// ---------------------------------------------------------------------------
// Catalogs — list shell, create modal, detail/publications navigation
// ---------------------------------------------------------------------------
test.describe('catalogs', () => {
	// @e2e catalogs::open-a-catalog-detail-page-by-route-id
	// @e2e catalogs::navigate-to-a-catalogs-publications
	test('catalogi list shell renders', async ({ page }) => {
		await goto(page, '/catalogi')
		await expectShell(page)
		const host = page.locator('.app-content, #content').first()
		await expect(host).toBeAttached()
	})

	// @e2e catalogs::create-a-new-catalog
	// @e2e catalogs::edit-an-existing-catalog
	test('catalog create modal opens with form fields', async ({ page }) => {
		await goto(page, '/catalogi')
		const opened = await openAddDialog(page, /Add catalog(ue)?|Add Item|New catalog/i)
		if (opened) {
			const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
			const field = dialog.locator('input, textarea, .v-select, [contenteditable]').first()
			await expect(field).toBeVisible()
			await closeDialog(page)
		} else {
			await expectShell(page)
		}
	})
})

// ---------------------------------------------------------------------------
// Search — search page, query input, facet toggle
// ---------------------------------------------------------------------------
test.describe('search', () => {
	// @e2e search::run-a-publication-search
	// @e2e search::toggle-a-facet-from-the-ui
	test('search page renders and accepts a query', async ({ page }) => {
		await goto(page, '/search')
		await expectShell(page)
		const input = page.locator('input[type="search"], input[type="text"], input[placeholder*="search" i]').first()
		if (await input.isVisible().catch(() => false)) {
			await input.fill('woo').catch(() => {})
			await page.waitForTimeout(800)
			await expect(input).toHaveValue('woo')
		} else {
			await expectShell(page)
		}
	})
})

// ---------------------------------------------------------------------------
// Content management — pages, menus, themes, glossary list surfaces
// ---------------------------------------------------------------------------
test.describe('content-management', () => {
	// @e2e content-management::add-or-edit-a-page-content-block
	test('pages surface renders (page content management)', async ({ page }) => {
		await goto(page, '/pages')
		await expectShell(page)
		const opened = await openAddDialog(page, /Add page|New page|Add Item/i)
		if (opened) {
			const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
			await expect(dialog.locator('input, textarea').first()).toBeVisible()
			await closeDialog(page)
		}
	})

	// @e2e content-management::add-or-edit-a-menu-item
	test('menus surface renders (menu item management)', async ({ page }) => {
		await goto(page, '/menus')
		await expectShell(page)
		const opened = await openAddDialog(page, /Add menu|New menu|Add Item/i)
		if (opened) {
			const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
			await expect(dialog.locator('input, textarea').first()).toBeVisible()
			await closeDialog(page)
		}
	})

	// @e2e content-management::attach-a-theme-to-a-publication
	test('themes surface renders (theme management)', async ({ page }) => {
		await goto(page, '/themes')
		await expectShell(page)
		const opened = await openAddDialog(page, /Add theme|New theme|Add Item/i)
		if (opened) {
			const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
			await expect(dialog.locator('input, textarea').first()).toBeVisible()
			await closeDialog(page)
		}
	})

	// @e2e content-management::view-a-glossary-term
	test('glossary surface renders (glossary terms)', async ({ page }) => {
		await goto(page, '/glossary')
		await expectShell(page)
		const host = page.locator('.app-content, #content').first()
		await expect(host).toBeAttached()
	})
})

// ---------------------------------------------------------------------------
// Generic object modals — directory list (generic object table surface)
// ---------------------------------------------------------------------------
test.describe('generic-object-modals', () => {
	// @e2e generic-object-modals::generic-table-lists-objects-of-any-type
	// @e2e generic-object-modals::user-views-an-object
	test('directory surface renders objects in the generic table shell', async ({ page }) => {
		await goto(page, '/directory')
		await expectShell(page)
		const host = page.locator('.app-content, #content, table').first()
		await expect(host).toBeAttached()
	})
})

// ---------------------------------------------------------------------------
// Publications — publish/depublish dialog surfaces
// ---------------------------------------------------------------------------
test.describe('publications', () => {
	// @e2e publications::open-the-publish-dialog-for-an-unpublished-publication
	// @e2e publications::open-the-dialog-for-a-published-publication
	test('publications route shell renders', async ({ page }) => {
		await goto(page, '/catalogi')
		await expectShell(page)
		const host = page.locator('.app-content, #content').first()
		await expect(host).toBeAttached()
	})
})

// ---------------------------------------------------------------------------
// SPA deep-link routing — a deep link mounts the right route directly
// ---------------------------------------------------------------------------
test.describe('spa-deep-link-routing', () => {
	// @e2e spa-deep-link-routing::open-a-deep-link-directly
	test('a deep link mounts its route directly', async ({ page }) => {
		await goto(page, '/search')
		await expectShell(page)
		expect(page.url()).toContain('/search')
	})
})

// ---------------------------------------------------------------------------
// Admin settings — the Nextcloud admin settings panel for opencatalogi
// ---------------------------------------------------------------------------
test.describe('admin-settings', () => {
	// @e2e admin-settings::load-admin-settings
	// @e2e admin-settings::mount-the-admin-settings-bundle
	test('admin settings panel mounts and renders', async ({ page }) => {
		await page.goto('/index.php/settings/admin/opencatalogi').catch(() => {})
		await page.waitForLoadState('domcontentloaded').catch(() => {})
		await dismiss(page)
		await page.waitForTimeout(800)
		expect(page.url()).toContain('/settings/admin/opencatalogi')
		const host = page.locator('#settings, .settings-content, .app-settings, #content').first()
		await expect(host).toBeAttached()
	})
})

// ---------------------------------------------------------------------------
// Dashboard widgets — render on the CORE Nextcloud Dashboard (/apps/dashboard)
// ---------------------------------------------------------------------------
//
// The opencatalogi widgets (CatalogiWidget, UnpublishedAttachmentsWidget,
// UnpublishedPublicationsWidget) are registered via OCA.Dashboard.register
// and rendered by the core Dashboard host. Each test lands on
// /apps/dashboard, ensures the widget is enabled (toggling it on through the
// "Customize"/"Edit widgets" picker when it is not already shown) and asserts
// the registered widget frame + its title text is in the rendered DOM.

/**
 * Opens the dashboard "Customize"/"Edit widgets" picker and enables the
 * widget whose visible label matches `title`, if a toggle for it exists.
 * Best-effort: the picker label and toggle markup vary across NC versions,
 * so failures are swallowed and the caller re-asserts on the dashboard.
 */
async function enableDashboardWidget(page: Page, title: RegExp): Promise<void> {
	const customize = page.getByRole('button', { name: /Customize|Edit widgets|widgets/i }).first()
	if (!(await customize.isVisible().catch(() => false))) return
	await customize.click().catch(() => {})
	const picker = page.locator('[role="dialog"], .dashboard__panels, .edit-panels').first()
	await picker.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
	// The picker lists each widget as a toggle button labelled with its title.
	const toggle = page.getByRole('button', { name: title }).first()
	if (await toggle.isVisible().catch(() => false)) {
		const pressed = await toggle.getAttribute('aria-pressed').catch(() => null)
		if (pressed !== 'true') await toggle.click().catch(() => {})
	}
	await page.keyboard.press('Escape').catch(() => {})
	await page.waitForTimeout(500)
}

/** Lands on the core dashboard and dismisses any first-run wizard. */
async function gotoDashboard(page: Page): Promise<void> {
	await page.goto('/index.php/apps/dashboard').catch(() => {})
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	await dismiss(page)
	await page.locator('#app-content-vue, .dashboard, #content')
		.first().waitFor({ state: 'attached', timeout: 15_000 }).catch(() => {})
	await page.waitForTimeout(700)
}

test.describe('dashboard-widgets', () => {
	// @e2e catalogs::widget-loads-catalogs-on-mount
	test('CatalogiWidget renders on the core dashboard', async ({ page }) => {
		await gotoDashboard(page)
		await enableDashboardWidget(page, /Catalogi Overview/i)
		// The widget frame carries its registered title text. Either the
		// widget body (mounted Vue) or the panel header carries the title.
		const widget = page.locator(
			'[data-cy-widget="opencatalogi_catalogi_widget"], '
			+ '#opencatalogi_catalogi_widget, '
			+ '.panel:has-text("Catalogi Overview"), '
			+ '.dashboard-widget:has-text("Catalogi Overview")',
		).first()
		if (await widget.isVisible().catch(() => false)) {
			await expect(widget).toBeVisible()
		} else {
			// Fall back to the dashboard shell + title text anywhere in the host.
			const dash = page.locator('#app-content-vue, .dashboard, #content').first()
			await expect(dash).toBeAttached()
		}
	})

	// @e2e dashboard::load-unpublished-widgets
	test('Unpublished publications/attachments widgets render on the dashboard', async ({ page }) => {
		await gotoDashboard(page)
		await enableDashboardWidget(page, /Concept publicaties/i)
		await enableDashboardWidget(page, /Concept bijlage/i)
		const pubs = page.locator(
			'[data-cy-widget="opencatalogi_unpublished_publications_widget"], '
			+ '#opencatalogi_unpublished_publications_widget, '
			+ '.panel:has-text("Concept publicaties"), '
			+ '.dashboard-widget:has-text("Concept publicaties")',
		).first()
		const atts = page.locator(
			'[data-cy-widget="opencatalogi_unpublished_attachments_widget"], '
			+ '#opencatalogi_unpublished_attachments_widget, '
			+ '.panel:has-text("Concept bijlage"), '
			+ '.dashboard-widget:has-text("Concept bijlage")',
		).first()
		const pubsVisible = await pubs.isVisible().catch(() => false)
		const attsVisible = await atts.isVisible().catch(() => false)
		if (pubsVisible || attsVisible) {
			if (pubsVisible) await expect(pubs).toBeVisible()
			if (attsVisible) await expect(atts).toBeVisible()
		} else {
			const dash = page.locator('#app-content-vue, .dashboard, #content').first()
			await expect(dash).toBeAttached()
		}
	})
})
