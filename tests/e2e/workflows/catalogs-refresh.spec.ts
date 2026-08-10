/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CAT-017 — the catalogs index refresh control re-loads the list.
 *
 * openspec/specs/catalogs/spec.md
 *   #requirement-the-catalogs-index-refresh-control-re-loads-the-list-and-reports-progress-cat-017
 *
 * WHY THIS EXISTS
 *
 * CnIndexPage emits `@refresh` and renders a spinner from its `:refreshing`
 * prop, but it does not own the fetch. A page driving its own useListView has
 * to do both: perform the refresh AND toggle `:refreshing`. CatalogiIndex
 * bound `:refreshing="isRefreshing"` while wiring `@refresh` straight to the
 * raw `refresh()`, so the list re-loaded but the control never showed that
 * anything was happening.
 *
 * The assertion here is on the RE-FETCH: activating the control must put a
 * fresh collection request on the wire. It is deliberately written against
 * observed traffic rather than a hardcoded URL, because the collection
 * endpoint is resolved from the object store at runtime and pinning a literal
 * path would make this fail for a reason that has nothing to do with the
 * requirement.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/catalogs-refresh
 */
import { test, expect, type Page, type Request } from '@playwright/test'

const APP = '/index.php/apps/opencatalogi'

/**
 * A request that looks like a data fetch for the list, as opposed to an asset.
 * Kept broad on purpose — see the file header.
 */
function isCollectionRequest(url: string): boolean {
	if (!/\/(api|apps)\//.test(url)) return false
	if (/\.(js|css|svg|png|woff2?|map|ico)(\?|$)/.test(url)) return false
	return /object|catalog/i.test(url)
}

/**
 * Remove the overlays that sit on top of the page toolbar.
 *
 * Two of them, and the second is easy to miss: besides the support dialog,
 * the first-run walkthrough renders a full-viewport dim
 * (`.cn-walkthrough__dim--full`) that INTERCEPTS POINTER EVENTS. Playwright
 * reports the refresh button as "visible, enabled and stable" and then retries
 * the click until the test times out, so the failure reads like a broken
 * button rather than a modal in front of it.
 */
async function dismissOverlays(page: Page): Promise<void> {
	await page.evaluate(() => {
		document
			.querySelectorAll(
				'.cn-support-dialog, [class*="support-dialog"], .cn-walkthrough, .cn-walkthrough__dim',
			)
			.forEach((el) => el.remove())
	})
}

test.describe('Catalogs index refresh', () => {

	test(
		// @e2e catalogs::refreshing-the-catalogs-list-re-fetches-it
		'CAT-017 — activating refresh puts a fresh collection request on the wire',
		async ({ page }) => {
			const seen: string[] = []
			page.on('request', (req: Request) => {
				if (isCollectionRequest(req.url())) seen.push(req.url())
			})

			await page.goto(`${APP}/#/catalogi`, { waitUntil: 'domcontentloaded' })
			await dismissOverlays(page)

			// The page must genuinely be the catalogs index before anything is
			// asserted about its controls.
			const refreshButton = page.getByRole('button', { name: /refresh/i }).first()
			await expect(refreshButton).toBeVisible({ timeout: 30000 })

			// Positive control on the listener: the initial load must itself have
			// produced collection traffic. Without this, a refresh that fired
			// nothing and a listener that captured nothing look identical.
			await expect
				.poll(() => seen.length, {
					timeout: 30000,
					message: 'the catalogs page issued no collection request on load — the request '
						+ 'listener is not observing what this test assumes it observes',
				})
				.toBeGreaterThan(0)

			// WAIT FOR THE LIST TO ACTUALLY RENDER, THEN FOR THE WIRE TO GO QUIET.
			//
			// Both halves are load-bearing, and each was learned by watching this
			// test pass when it should not have:
			//
			//   1. A quiescence loop alone settled at THREE requests — `goto`
			//      resolves on domcontentloaded, before the SPA has begun
			//      fetching, so "no traffic for a second" was true simply because
			//      nothing had started yet. The app's own boot traffic then
			//      supplied the growth the assertion was looking for, and the
			//      test passed with the click REMOVED.
			//   2. So the list must be on screen first. Waiting for rendered rows
			//      means the collection fetch has already happened and been
			//      applied, which is the only state where "no new requests" means
			//      idle rather than "not started".
			//
			//   3. Even then, a TWO-second window still settled one request early.
			//      Instrumenting it showed the page fetches its schemas, pauses
			//      for more than two seconds, and only then fetches the catalog
			//      collection itself — so the window closed, and that single late
			//      request became the "growth" the assertion wanted. Five seconds
			//      is longer than the app's own gap between those two phases.
			//
			// The rule this encodes: a quiet window is only evidence of idleness
			// if it is longer than the subject's own longest pause.
			await expect(page.getByRole('table').or(page.getByRole('list')).first())
				.toBeVisible({ timeout: 30000 })

			async function waitForQuiet(): Promise<void> {
				let last = -1
				while (last !== seen.length) {
					last = seen.length
					await page.waitForTimeout(5000)
				}
			}
			await waitForQuiet()

			const beforeRefresh = seen.length
			await refreshButton.click()

			await expect
				.poll(() => seen.length, {
					timeout: 30000,
					message: 'activating the refresh control issued no new collection request — '
						+ 'the control is wired to nothing',
				})
				.toBeGreaterThan(beforeRefresh)
		},
	)
})
