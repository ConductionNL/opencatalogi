/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression guard for the `/woo` green-but-dead defect
 * (fix-woo-capability-provisioning, WOO-PROV-001..003).
 *
 * Before the fix this page rendered an empty table while the console logged
 * two 404s, because the manifest's `@resolve:woo_register` /
 * `@resolve:woo_batch_schema` sentinels were never substituted — the literal
 * sentinel strings were sent to OpenRegister as register/schema identifiers.
 * Nothing in the unit suite could see that: the sentinels are substituted in
 * `src/main.js` from initial state that only exists on a provisioned
 * instance, so this failure mode is only observable in a real browser
 * against a real install.
 *
 * The spec-side scenarios are marked `@e2e exclude` (they assert backend
 * contracts), so this file is the deliberate browser-level complement: it
 * fails if a resolve-sentinel ever reaches the network again, or if the WOO
 * page stops resolving its schema.
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, content, trackPageErrors, fatalErrors } from './_nav'

test.describe('woo-batches-page', () => {

	test('WOO-PROV-003 — the WOO page resolves real register/schema ids, never a literal @resolve sentinel', async ({ page }) => {
		const errors = trackPageErrors(page)

		// Every OpenRegister call the page makes, so we can prove none of them
		// carries an unsubstituted sentinel.
		const openRegisterCalls: string[] = []
		const failedCalls: string[] = []
		page.on('request', (r) => {
			const u = r.url()
			if (u.includes('/apps/openregister/api/')) openRegisterCalls.push(u)
		})
		page.on('response', (r) => {
			const u = r.url()
			if (u.includes('/apps/openregister/api/') && r.status() >= 400) {
				failedCalls.push(`${r.status()} ${u}`)
			}
		})

		await bootApp(page)

		// WooBatchesMenu is rendered inside the collapsible "Catalogue" group
		// (`li.app-navigation-entry--collapsible[data-testid=cn-nav-entry-CatalogueGroup]`),
		// so it is in the DOM but zero-height until the group is expanded.
		// Expand it first, then use the normal nav click.
		const group = page.locator('[data-testid="cn-nav-entry-CatalogueGroup"]').first()
		await expect(group).toBeVisible({ timeout: 10000 })
		const wooEntry = page.locator('[data-testid="cn-nav-entry-WooBatchesMenu"]').first()
		if (!(await wooEntry.isVisible().catch(() => false))) {
			await group.locator('a, button').first().click()
			await expect(wooEntry).toBeVisible({ timeout: 10000 })
		}

		await navTo(page, 'WooBatchesMenu')

		// 1. The defect itself: a literal sentinel reaching the network.
		const sentinelCalls = openRegisterCalls.filter((u) => u.includes('@resolve:'))
		expect(
			sentinelCalls,
			'An unsubstituted "@resolve:<key>" sentinel was sent to OpenRegister as a '
			+ 'register/schema id — it will 404. The key is missing from '
			+ 'ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS, or its app-config '
			+ 'value was never provisioned (see fix-woo-capability-provisioning).',
		).toEqual([])

		// 2. No 4xx/5xx from the object/schema calls this page depends on.
		expect(failedCalls, 'OpenRegister call(s) failed on the WOO page').toEqual([])

		// 3. The page actually resolved its schema: the list surface renders.
		//    An empty batch list is the expected steady state on a fresh install —
		//    what matters is that it rendered a real, schema-aware surface rather
		//    than dying on a 404.
		await expect(content(page)).toBeVisible()
		await expect(
			content(page).getByRole('button', { name: /add\s*woo\s*batch/i })
				.or(content(page).getByText(/no items found/i))
				.first(),
		).toBeVisible({ timeout: 15000 })

		expect(fatalErrors(errors), 'Uncaught JS error(s) on the WOO page').toEqual([])
	})

})
