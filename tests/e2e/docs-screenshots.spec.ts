/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — opencatalogi.
 *
 * This spec is *not* a regression test — it drives the OpenCatalogi UI
 * through the flows documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: OpenCatalogi reads from OpenRegister. On a dev
 * container the OC version may lag behind the bundled OR — the list
 * pages still render structurally even when the Vue app's data layer
 * is initialising, and the create dialogs open on every list view
 * (filled-form vs. empty depends on whether the publication schema is
 * mapped). Where a flow depends on a specific item / catalogue
 * existing, the spec falls back to the relevant list / settings page
 * if nothing is reachable. The markdown shape is final; re-captures
 * pick up the real data as soon as it lands.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')
const APP = '/apps/opencatalogi'

async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({ path: path.join(dir, file), fullPage: false, type: 'png' })
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (await stray.first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

async function go(page: Page, route: string): Promise<void> {
	const url = route.startsWith('/apps/') || route.startsWith('/settings/')
		? `/index.php${route}`
		: `/index.php${APP}${route}`
	await page.goto(url).catch(() => { /* tolerate a 404 — caller decides */ })
	await page.waitForLoadState('networkidle').catch(() => { /* idle never fires on some pages */ })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

async function captureCreateDialog(page: Page, track: 'user' | 'admin', file: string, buttonRe: RegExp): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: buttonRe }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => { /* no dialog */ })
	await page.waitForTimeout(500)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/catalogi')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/opencatalogi')
	})

	test('UN browse-catalogue', async ({ page }) => {
		// docs/tutorials/user/02-browse-catalogue.md
		await go(page, '/catalogi')
		await shoot(page, 'user', '02-browse-catalogue-01.png')
		const firstCard = page.locator('.app-content a, .app-content .card a, table tbody tr').first()
		if (await firstCard.isVisible().catch(() => false)) {
			await firstCard.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '02-browse-catalogue-02.png')
		await shoot(page, 'user', '02-browse-catalogue-03.png')
		await shoot(page, 'user', '02-browse-catalogue-04.png')
		// Open the first publication if reachable, otherwise the
		// catalogue detail view stands in for step 5.
		const firstPub = page.locator('.app-content a, table tbody tr').first()
		if (await firstPub.isVisible().catch(() => false)) {
			await firstPub.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '02-browse-catalogue-05.png')
	})

	test('UN view-component-detail', async ({ page }) => {
		// docs/tutorials/user/03-view-component-detail.md — open a
		// publication's detail page if any is reachable, otherwise the
		// catalogi list stands in for every step.
		await go(page, '/catalogi')
		const firstCat = page.locator('.app-content a, table tbody tr').first()
		if (await firstCat.isVisible().catch(() => false)) {
			await firstCat.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '03-view-component-detail-01.png')
		await shoot(page, 'user', '03-view-component-detail-02.png')
		await shoot(page, 'user', '03-view-component-detail-03.png')
		await shoot(page, 'user', '03-view-component-detail-04.png')
		await shoot(page, 'user', '03-view-component-detail-05.png')
	})

	test('UN search-across-catalogues', async ({ page }) => {
		// docs/tutorials/user/04-search-across-catalogues.md
		await go(page, '/search')
		await shoot(page, 'user', '04-search-across-catalogues-01.png')
		const searchInput = page.locator('input[type="search"], input[placeholder*="Search" i]').first()
		if (await searchInput.isVisible().catch(() => false)) {
			await searchInput.fill('open').catch(() => {})
			await page.waitForTimeout(1000)
		}
		await shoot(page, 'user', '04-search-across-catalogues-02.png')
		await shoot(page, 'user', '04-search-across-catalogues-03.png')
		await shoot(page, 'user', '04-search-across-catalogues-04.png')
		await shoot(page, 'user', '04-search-across-catalogues-05.png')
	})

	test('UN subscribe-to-catalogue', async ({ page }) => {
		// docs/tutorials/user/05-subscribe-to-catalogue.md
		await go(page, '/directory')
		await shoot(page, 'user', '05-subscribe-to-catalogue-01.png')
		const firstPeer = page.locator('.app-content a, table tbody tr').first()
		if (await firstPeer.isVisible().catch(() => false)) {
			await firstPeer.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '05-subscribe-to-catalogue-02.png')
		await shoot(page, 'user', '05-subscribe-to-catalogue-03.png')
		await go(page, '/catalogi')
		await shoot(page, 'user', '05-subscribe-to-catalogue-04.png')
		await shoot(page, 'user', '05-subscribe-to-catalogue-05.png')
	})

	test('UN publish-an-item', async ({ page }) => {
		// docs/tutorials/user/06-publish-an-item.md
		await go(page, '/catalogi')
		const firstCat = page.locator('.app-content a, table tbody tr').first()
		if (await firstCat.isVisible().catch(() => false)) {
			await firstCat.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		const had = await captureCreateDialog(page, 'user', '06-publish-an-item-01.png', /Add publication|Add Item|Add Publication/i)
		if (had) {
			await captureCreateDialog(page, 'user', '06-publish-an-item-02.png', /Add publication|Add Item|Add Publication/i)
		} else {
			await shoot(page, 'user', '06-publish-an-item-01.png')
			await shoot(page, 'user', '06-publish-an-item-02.png')
		}
		await shoot(page, 'user', '06-publish-an-item-03.png')
		await shoot(page, 'user', '06-publish-an-item-04.png')
		await shoot(page, 'user', '06-publish-an-item-05.png')
	})

	test('UN link-related-items', async ({ page }) => {
		// docs/tutorials/user/07-link-related-items.md
		await go(page, '/catalogi')
		const firstCat = page.locator('.app-content a, table tbody tr').first()
		if (await firstCat.isVisible().catch(() => false)) {
			await firstCat.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '07-link-related-items-01.png')
		await shoot(page, 'user', '07-link-related-items-02.png')
		await shoot(page, 'user', '07-link-related-items-03.png')
		await shoot(page, 'user', '07-link-related-items-04.png')
		await shoot(page, 'user', '07-link-related-items-05.png')
	})

	test('UN export-publication', async ({ page }) => {
		// docs/tutorials/user/08-export-publication.md
		await go(page, '/catalogi')
		const firstCat = page.locator('.app-content a, table tbody tr').first()
		if (await firstCat.isVisible().catch(() => false)) {
			await firstCat.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		const actions = page.getByRole('button', { name: /Actions/i }).first()
		if (await actions.isVisible().catch(() => false)) {
			await actions.click().catch(() => {})
			await page.waitForTimeout(500)
		}
		await shoot(page, 'user', '08-export-publication-01.png')
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
		await shoot(page, 'user', '08-export-publication-02.png')
		await shoot(page, 'user', '08-export-publication-03.png')
		await shoot(page, 'user', '08-export-publication-04.png')
		await shoot(page, 'user', '08-export-publication-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN configure-catalogue', async ({ page }) => {
		// docs/tutorials/admin/01-configure-catalogue.md
		await go(page, '/catalogi')
		const had = await captureCreateDialog(page, 'admin', '01-configure-catalogue-01.png', /Add Catalogue|Add Catalog|Add Item/i)
		if (!had) {
			await shoot(page, 'admin', '01-configure-catalogue-01.png')
		}
		await go(page, '/catalogi')
		// Open the first catalogue's detail for the Settings tab captures.
		const firstCat = page.locator('.app-content a, table tbody tr').first()
		if (await firstCat.isVisible().catch(() => false)) {
			await firstCat.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'admin', '01-configure-catalogue-02.png')
		await shoot(page, 'admin', '01-configure-catalogue-03.png')
		await shoot(page, 'admin', '01-configure-catalogue-04.png')
		await shoot(page, 'admin', '01-configure-catalogue-05.png')
	})

	test('AN manage-federation-sources', async ({ page }) => {
		// docs/tutorials/admin/02-manage-federation-sources.md
		await go(page, '/directory')
		await shoot(page, 'admin', '02-manage-federation-sources-01.png')
		const had = await captureCreateDialog(page, 'admin', '02-manage-federation-sources-02.png', /Add source|Add Source|Add Item/i)
		if (!had) {
			await shoot(page, 'admin', '02-manage-federation-sources-02.png')
		}
		await go(page, '/directory')
		const firstPeer = page.locator('.app-content a, table tbody tr').first()
		if (await firstPeer.isVisible().catch(() => false)) {
			await firstPeer.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'admin', '02-manage-federation-sources-03.png')
		await shoot(page, 'admin', '02-manage-federation-sources-04.png')
		await go(page, '/catalogi')
		await shoot(page, 'admin', '02-manage-federation-sources-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md
		await go(page, '/settings/admin/opencatalogi')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		await page.evaluate(() => window.scrollBy(0, 400))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-03.png')
		await page.evaluate(() => window.scrollBy(0, 400))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/settings/admin/opencatalogi')
	})
})
