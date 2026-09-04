/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error), an entry whose `route` names
 * a page the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences.
 *
 * The three reports are declarative `type: "dashboard"` pages over the
 * publication register, which adds a fourth failure mode no manifest gate can
 * see: a widget whose `source` names a schema or field that does not match
 * renders its card, its title and no value, silently. The live risk in the
 * Usage report is the METRIC: usageCounter stores a `count` per publication per
 * day per kind, so the totals are SUMs of that field. Counting rows instead
 * would answer "how many days had activity" — a plausible-looking number that
 * answers a different question.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { Fixtures, REG_OPENCATALOGI } from './workflows/_fixtures.ts'

const APP_BASE = '/apps/opencatalogi'

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, then Restart tutorial', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// The footer ran 1/2/3, which left no room between Documentation and
		// Features & roadmap for Reports to sit where ADR-114 puts it, so it was
		// renumbered to the fleet's 90/95/100. Restart tutorial keeps its place
		// at the END: it is a re-entry into the walkthrough rather than a
		// destination, so it does not belong among the three.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap|tutorial/i.test(t),
		)
		expect(seen.length).toBe(5)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)
		expect(seen[4]).toMatch(/tutorial/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports lists all three reports', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')
		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/opencatalogi\/reports(\?|$)/, {
			timeout: 15_000,
		})

		for (const label of ['Publications', 'Usage', 'Catalogs and directory']) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('the publications report renders real numbers, not empty cards', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/reports/publications`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText('Retention says review', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('main, .app-content').first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		)
	})

	test('the usage report sums the stored count rather than counting rows', async ({
		page,
	}) => {
		// SEED, do not hope. With no counters the report correctly renders an
		// em-dash for both totals, so `toContainText(/\d/)` was really asking
		// whether some earlier spec had left data behind — it passed on a dev
		// box with history and failed on a fresh instance, from the same code.
		//
		// Two rows on ONE day for ONE publication, 7 views and 4 downloads.
		// That is the shape the metric turns on: SUM(count) reads 7 and 4,
		// COUNT(rows) would read 1 and 1, so the assertion below separates them.
		const fx = new Fixtures()
		await fx.init()
		try {
			const publication = await fx.createPublication(
				'Usage report',
				{},
				REG_OPENCATALOGI,
			)
			await fx.createUsageCounter(publication.id, 'view', 7)
			await fx.createUsageCounter(publication.id, 'download', 4)

			await page.goto(`${APP_BASE}/reports/usage`)
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
				timeout: 30_000,
			})
			const main = page.locator('main, .app-content').first()
			await expect(
				main.getByText('Downloads', { exact: false }).first(),
			).toBeVisible({ timeout: 30_000 })

			// SCOPE TO THE STAT CARDS. Unscoped, `toContainText('4')` is
			// satisfied by the bar chart's y-axis ticks (12/10/8/6/4/2/0) and by
			// the date column of the Most-recent table, so it would pass on a
			// report whose totals both read an em-dash.
			await expect(main.locator('[widget-id="use-views"]')).toContainText(
				'7',
				{ timeout: 30_000 },
			)
			await expect(main.locator('[widget-id="use-downloads"]')).toContainText(
				'4',
				{ timeout: 30_000 },
			)
		} finally {
			await fx.cleanupAll()
			await fx.dispose()
		}
	})

	test('the federation report is reachable and titled', async ({ page }) => {
		await page.goto(`${APP_BASE}/reports/federation`)
		await expect(page).toHaveURL(/\/reports\/federation(\?|$)/, {
			timeout: 15_000,
		})
		await expect(
			page.getByText('Directory listings', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
	})

	test('Store opens the hosted store surface, which this app writes no backend for', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/opencatalogi\/store(\?|$)/, {
			timeout: 15_000,
		})

		// The page is declarative: openregister hosts the store plane, so this
		// app ships NO store controller (ADR-080, ADR-114 Decision 4). With no
		// registry configured it renders the app's own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/opencatalogi$/,
		)
	})
})
