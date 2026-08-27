/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WHAT A FRESH DEMO INSTANCE MUST ACTUALLY PRODUCE.
 *
 * `opencatalogi-compose.yaml` claims to bring up a working OpenCatalogi demo
 * from nothing: Postgres, three release tarballs unpacked into a named volume,
 * Nextcloud, and a themed public portal at /apps/portaliq/site?portal=demo.
 *
 * Every assertion here exists because the corresponding failure was MEASURED on
 * a real clean instance on 2026-08-27, and every one of them was silent —
 * the install reported success in all four cases:
 *
 *   1. OpenCatalogi's register configuration was never imported, because
 *      InitializeSettings was registered only under <post-migration> and a first
 *      install runs neither pre- nor post-migration steps. Result: no registers,
 *      and /api/directory answering `{"results":[],"total":0}` — which reads as
 *      "no federation peers", not "this app was never configured".
 *
 *   2. The portal resolved to nothing, because a pending schema ref leaked from
 *      buildiq's boot() onto the shared ObjectService and refused portaliq's
 *      PortalResolver. PortalResolver fails closed, so every page 404'd with
 *      nothing in the error attributable to portaliq.
 *
 * A SMOKE TEST THAT ONLY CHECKS FOR HTTP 200 WOULD HAVE PASSED THROUGH BOTH.
 * `/apps/portaliq/site?portal=demo` returned 200 the whole time — Nextcloud
 * serves its page shell before the SPA decides it has nothing to render. So the
 * assertions below look at CONTENT: a named portal, a non-empty catalog, real
 * menu entries. Status codes are checked only where the endpoint is a JSON API
 * whose body is the answer.
 *
 * TARGET. Runs against NEXTCLOUD_URL like the rest of the suite, which has no
 * default on purpose (see base-url.ts) — an unset value must fail loudly rather
 * than quietly aim at the shared dev container, which is a write path.
 * For the demo rig that is http://localhost:8600.
 *
 * @e2e openspec/specs/federation/spec.md
 */

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url'

/**
 * The demo portal is public by design — `authentication.modes: ["public"]`.
 * Reading it while authenticated as admin would prove nothing about what a
 * visitor sees, and admin can read objects RBAC would hide from anonymous
 * users. Every test here therefore drops the suite's stored admin session.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * OPT-IN, AND DELIBERATELY NOT AUTO-DETECTED.
 *
 * These assertions describe a rig that OpenCatalogi's CI does not build: the
 * portal ones need Portaliq installed and its demo portal seeded, and
 * `ci-seed.sh` installs neither. Running them there would fail for a reason
 * that has nothing to do with the change under test.
 *
 * The obvious alternative — probe for the portal and skip when it is absent —
 * is worse, because a skip cannot tell "this rig was never meant to have a
 * portal" from "the seeder should have produced one and did not". The second is
 * a real regression and it would be reported as a pass.
 *
 * So the gate is an explicit environment variable. Absent, the suite states
 * plainly that it is not looking. Present, a missing portal is a FAILURE.
 *
 *   CONNEXT_DEMO=1 NEXTCLOUD_URL=http://localhost:8600 \
 *     npx playwright test --config tests/e2e/playwright.config.ts demo-environment
 */
const IS_DEMO_RIG = process.env.CONNEXT_DEMO === '1'

test.describe('demo environment', () => {
	test.skip(
		!IS_DEMO_RIG,
		'Not a Connext demo rig. Set CONNEXT_DEMO=1 and point NEXTCLOUD_URL at one '
		+ '(the compose serves http://localhost:8600) to run these.',
	)

	test('the seeded portal resolves and renders its own identity', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/portaliq/site?portal=demo`)

		// The portal's OWN title, not Nextcloud's. When PortalResolver returns
		// null the page still loads and the tab reads "Portaal" — the generic
		// shell — so asserting the seeded name is what separates "resolved" from
		// "rendered the fallback".
		await expect(page).toHaveTitle(/Open Catalogi/i)
		await expect(page.getByRole('heading', { name: 'Open Catalogi', level: 1 })).toBeVisible()

		// The search hero is the portal's reason to exist.
		await expect(page.getByRole('search')).toBeVisible()

		// Footer chrome comes from the seeded menu objects, not from a template,
		// so its presence proves the CMS read succeeded rather than that a layout
		// rendered.
		await expect(page.getByRole('link', { name: 'Privacy' })).toBeVisible()
	})

	test('the content API serves the seeded portal, its menus and its pages', async ({ request }) => {
		const site = await request.get(`${BASE_URL}/apps/portaliq/api/content/site?portal=demo`)
		expect(site.status()).toBe(200)
		const siteBody = await site.json()
		// `{"error":"not_found"}` also arrives as a JSON body, so assert the shape
		// rather than merely that JSON came back.
		expect(siteBody).toMatchObject({ slug: 'demo', title: 'Open Catalogi' })
		expect(siteBody.theme).toBe('opencatalogi')

		const pages = await request.get(`${BASE_URL}/apps/portaliq/api/content/pages?portal=demo`)
		expect(pages.status()).toBe(200)
		const pageBody = await pages.json()
		expect(Array.isArray(pageBody.pages)).toBe(true)
		// Home / Publicatie / Zoeken are seeded by InitializeDemoPortal.
		expect(pageBody.pages.length).toBeGreaterThan(0)
		expect(pageBody.pages.map((p: { route: string }) => p.route)).toContain('/')

		const menus = await request.get(`${BASE_URL}/apps/portaliq/api/content/menus?portal=demo`)
		expect(menus.status()).toBe(200)
		const menuBody = await menus.json()
		expect(Array.isArray(menuBody.menus)).toBe(true)
		expect(menuBody.menus.length).toBeGreaterThan(0)
	})

	test('OpenCatalogi imported its register configuration on install', async ({ request }) => {
		// THE ASSERTION THAT CATCHES THE SILENT INSTALL FAILURE.
		//
		// This endpoint answers 200 with `{"results":[],"total":0}` both when the
		// instance genuinely has no catalogs AND when the register was never
		// created. Only the non-empty case proves the install actually configured
		// the app, which is why the count — not the status — is the assertion.
		const directory = await request.get(`${BASE_URL}/apps/opencatalogi/api/directory`)
		expect(directory.status()).toBe(200)

		const body = await directory.json()
		expect(Array.isArray(body.results)).toBe(true)
		expect(
			body.results.length,
			'the directory is empty — on a fresh instance this means the register '
			+ 'configuration was never imported, not that there are no peers',
		).toBeGreaterThan(0)

		// The seeded catalog carries schemas; a catalog with none is the
		// half-configured state where publishing silently finds nothing to scope.
		const [catalog] = body.results
		expect(catalog.title).toBeTruthy()
		expect(Array.isArray(catalog.schemas)).toBe(true)
		expect(catalog.schemas.length).toBeGreaterThan(0)
	})

	test('the instance refuses to advertise its local address to the directory', async ({ request }) => {
		// A demo runs on localhost, and a localhost URL is unroutable for every
		// peer. The instance must still SERVE its own directory endpoint — that is
		// how a peer on the same docker network federates with it — while
		// declining to broadcast that address outward. Serving is what we can
		// observe from here; the refusal is covered by the unit tests, because
		// asserting it end-to-end would mean letting a broadcast leave the box.
		const own = await request.get(`${BASE_URL}/apps/opencatalogi/api/directory`)
		expect(own.status()).toBe(200)
		expect(own.headers()['content-type']).toContain('application/json')
	})
})
