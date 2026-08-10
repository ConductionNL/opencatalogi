/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drives the /search page — `src/views/search/FederationSearch.vue` — in a
 * real browser.
 *
 * WHY THIS PAGE NEEDS A BROWSER TEST AT ALL
 *
 * FederationSearch exists to fix a specific, silent regression. The manifest
 * entry records it:
 *
 *   "FederationSearch (custom) wraps CnSearchPage with the federation-aware
 *    search store so /search hits /api/federation/publications. The previous
 *    type:'search' default fanned out per-schema against the local
 *    OpenRegister, bypassing federation entirely."
 *
 * That is a NETWORK-TARGET contract, and it is invisible to every other layer
 * of the suite. A unit test on the store proves the store is correct; it does
 * not prove the /search ROUTE renders this component rather than the built-in
 * type:'search' page. If the manifest entry were reverted to the default, the
 * page would still render, still show a search box, and still return results —
 * from the local register only, with federated instances silently missing.
 * Nothing would go red. The only way to catch it is to open the route and
 * watch where the request goes.
 *
 * This is the SCH-OR-004 requirement that `FederationSearch.onSearch` is
 * tagged with:
 *   openspec/specs/search/spec.md
 *     #requirement-search-frontend-store-calls-the-federation-endpoint-sch-or-004
 * "The frontend search store MUST query publications via the federation
 *  endpoint GET /api/federation/publications ... MUST NOT call OR's
 *  zoeken-filteren endpoint directly."
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/federation-search
 */
import { test, expect, type Locator, type Page, type Request } from '@playwright/test'

const APP = '/index.php/apps/opencatalogi'

/** The endpoint the search page is REQUIRED to use. */
const FEDERATION_ENDPOINT = '/apps/opencatalogi/api/federation/publications'

/**
 * The endpoint it must NOT call directly. OpenRegister's own search surface —
 * reaching it from the frontend is the exact bypass SCH-OR-004 forbids.
 */
const FORBIDDEN_DIRECT_ENDPOINT = 'zoeken-filteren'

/**
 * Dismiss the support dialog, which auto-opens over the page and swallows
 * clicks. Mirrors tests/e2e/visual/_visual-helpers.ts::dismissSupportDialog.
 */
async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [class*="support-dialog"]')
	if (await dialog.count() > 0) {
		await page.evaluate(() => {
			document
				.querySelectorAll('.cn-support-dialog, [class*="support-dialog"]')
				.forEach((el) => el.remove())
		})
	}
}

/**
 * The app's own search box, scoped to <main>.
 *
 * Scoping matters: Nextcloud's chrome ships its own inputs — the contacts
 * menu renders a permanently-hidden `input[type="search"]`
 * (#contactsmenu__menu__search) OUTSIDE main. A bare
 * `input[type="search"]` selector resolves to that one first and waits
 * forever on a hidden element, which is how the first draft of this spec
 * failed against a page that was rendering perfectly.
 */
function appSearchBox(page: Page): Locator {
	return page.getByRole('main').getByRole('searchbox').first()
}

test.describe('Federation search page', () => {

	test('SCH-OR-004 — /search renders FederationSearch and queries the federation endpoint', async ({ page }) => {
		const searchRequests: string[] = []
		const forbiddenRequests: string[] = []

		page.on('request', (req: Request) => {
			const url = req.url()
			if (url.includes(FEDERATION_ENDPOINT)) {
				searchRequests.push(url)
			}
			if (url.includes(FORBIDDEN_DIRECT_ENDPOINT)) {
				forbiddenRequests.push(url)
			}
		})

		await page.goto(`${APP}/#/search`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)

		// The page must actually mount a search surface. Asserting on the
		// search box rather than on any text keeps this from passing on an
		// error page that happens to contain the word "search".
		await expect(page.getByRole('heading', { name: 'Search publications' }))
			.toBeVisible({ timeout: 30000 })
		await expect(appSearchBox(page)).toBeVisible({ timeout: 30000 })

		// The page loads results on mount (loadInitialResults), so the
		// federation endpoint should already have been hit before we type.
		await expect
			.poll(() => searchRequests.length, {
				timeout: 30000,
				message:
					'the /search page never called /api/federation/publications — it is probably rendering the built-in '
					+ "type:'search' page against the local register instead of FederationSearch",
			})
			.toBeGreaterThan(0)

		// SCH-OR-004's prohibition, asserted directly.
		expect(
			forbiddenRequests,
			'the frontend must never call zoeken-filteren directly (SCH-OR-004)',
		).toEqual([])
	})

	test('SCH-OR-004 — typing a term sends it to the federation endpoint', async ({ page }) => {
		const searchUrls: string[] = []
		page.on('request', (req: Request) => {
			if (req.url().includes(FEDERATION_ENDPOINT)) {
				searchUrls.push(req.url())
			}
		})

		await page.goto(`${APP}/#/search`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)

		const searchInput = appSearchBox(page)
		await expect(searchInput).toBeVisible({ timeout: 30000 })

		// A term unlikely to collide with seeded content, so the assertion is
		// about the REQUEST, not about how many rows come back.
		const term = 'opencatalogi-e2e-federation-probe'
		await searchInput.fill(term)
		await searchInput.press('Enter')
		await page.getByRole('main').getByRole('button', { name: 'Search' }).click()

		// onSearch() destructures the CnSearchPage payload before setting the
		// term. If that destructuring regressed, the query string would read
		// `_search=[object Object]` — which is exactly the bug the method's
		// docblock records, and which a "did we get a 200" assertion misses.
		await expect
			.poll(
				() => searchUrls.some((u) => decodeURIComponent(u).includes(term)),
				{
					timeout: 30000,
					message:
						`no federation request carried the search term "${term}" — if the query shows `
						+ '_search=[object Object], onSearch() stopped destructuring the payload',
				},
			)
			.toBe(true)

		for (const url of searchUrls) {
			expect(
				decodeURIComponent(url),
				'the search term must never be serialised as [object Object]',
			).not.toContain('[object Object]')
		}
	})
})
