/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) browser proof for the REACHABLE custom page
 * components OpenCatalogi had no gate-visible proof for:
 *
 *   src/views/catalogi/CatalogiIndex.vue      (manifest page `Catalogs`,
 *                                              registered as `CatalogsIndexView`)
 *   src/views/woo/WooBatchDetail.vue          (manifest page `WooBatchDetail`,
 *                                              registered as `WooBatchDetailView`)
 *   src/views/directory/FederationDirectory.vue (manifest page `Directory`)
 *
 * FederationDirectory is here for a reason worth recording. It was NOT in
 * gate-26's finding list, and it was not covered either — a CSS comment in that
 * file read "… @visual exclude scoped copy of .viewHeaderTitleIndented …",
 * ordinary English in which `@visual` happens to precede `exclude`. gate-26's
 * `_VISUAL_EXCLUDE_RE` scans the whole file text and does not require the marker
 * to be a directive, so that sentence silently waived a real, routed, shipped
 * page. Measured both ways on the same tree: neutering those two words alone
 * turned gate-26 from PASS into `FAIL — 1` naming this file. The prose has been
 * reworded and the page is now proved here instead.
 *
 * (`directory-page.spec.ts` does already drive the page for real, but it never
 * spells the string `FederationDirectory`, and gate-26 matches on the file stem
 * — so that genuine coverage was invisible to the gate. A gate-26 finding is
 * therefore not evidence a page is untested, in either direction.)
 *
 * WHY THIS FILE AND NOT A tests/e2e/visual/ BASELINE
 * -------------------------------------------------
 * Gate-26 accepts three proofs: a baseline under `tests/e2e/visual/**`, an e2e
 * test anywhere under `tests/e2e/**`, or an `@visual exclude`. On this repo the
 * first one is not a proof at all. The shared workflow is called with
 * `playwright-test-path: tests/e2e`, so it loads `tests/e2e/playwright.config.ts`,
 * which declares exactly one project:
 *
 *     { name: 'chromium', testIgnore: ['**‍/docs-screenshots.spec.ts', '**‍/visual/**'] }
 *
 * and the workflow never passes `--project`. A PNG committed under
 * `tests/e2e/visual/` therefore turns gate-26 green with nothing ever executing
 * it. (The root `playwright.config.ts` does declare a `visual` project, but its
 * own header records that host-font/GPU rendering means its baselines cannot
 * byte-match a CI Linux runner, which is why the CI config excludes it.)
 *
 * So the proof lives here, in the directory CI actually runs.
 *
 * WHAT EACH TEST HAS TO DO TO BE WORTH ANYTHING
 * ---------------------------------------------
 * Nextcloud paints its header, navigation and app shell on every route,
 * including routes that silently fell back to the dashboard. "A page rendered"
 * is therefore worth nothing, and so is a screenshot of chrome. Each test below
 * asserts a string or structure that ONLY the component under test can produce,
 * and pairs it with a control that makes the SAME locator resolve differently
 * — a broken locator cannot produce a difference, so the pair is only
 * satisfiable by the component genuinely rendering.
 *
 * THE OTHER TEN `src/views/**` COMPONENTS ARE NOT COVERED HERE ON PURPOSE.
 * They are imported by nothing, routed to by nothing, and webpack tree-shakes
 * every one of them out of `js/opencatalogi-main.js`. They carry an
 * `@visual exclude` naming ConductionNL/opencatalogi#849 instead, because you
 * cannot drive a screen that nothing can reach.
 *
 * Run:
 *   PLAYWRIGHT_BASE_URL=http://localhost:8296 \
 *     npx playwright test --config=tests/e2e/playwright.config.ts spec-coverage/page-components
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { APP, bootApp, dismissOverlays, content } from './_nav'

/** OpenRegister object API root for the publication register. */
const OR_OBJECTS = '/index.php/apps/openregister/api/objects'

/** Marks every fixture this run creates, so cleanup and assertions are run-scoped. */
const RUN_ID = `oc-g26-${Date.now()}`

/**
 * Copy that ONLY `src/views/catalogi/CatalogiIndex.vue` passes to CnIndexPage.
 * The generic manifest `type: "index"` pages (Organizations, Themes, Glossary,
 * Pages, Menus) take their title and description from `src/manifest.json` and
 * never render this string, which is what makes it a discriminating locator
 * rather than a "the shell booted" locator.
 */
const CATALOGI_INDEX_DESCRIPTION = 'Manage your data catalogs and their configurations'

/**
 * Resolve the OpenRegister schema id for a slug.
 *
 * Ids are assigned at import time and differ per instance, so hardcoding one
 * would make this suite pass or fail on the ordering of a register import.
 *
 * @param request An authenticated API request context.
 * @param slug The schema slug (e.g. `wooBatch`).
 * @return The numeric schema id as a string.
 */
async function schemaId(request: APIRequestContext, slug: string): Promise<string> {
	const resp = await request.get('/index.php/apps/openregister/api/schemas?limit=200', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(resp.status(), 'GET /apps/openregister/api/schemas must answer 200').toBe(200)
	const body = await resp.json()
	const rows: Array<Record<string, unknown>> = body?.results ?? (Array.isArray(body) ? body : [])
	const hit = rows.find((s) => s.slug === slug)
	expect(hit, `schema "${slug}" must exist on this instance — the register import is a precondition`).toBeTruthy()
	return String(hit!.id)
}

/**
 * Resolve the register id that owns a schema.
 *
 * @param request An authenticated API request context.
 * @param slug The register slug.
 * @return The numeric register id as a string.
 */
async function registerId(request: APIRequestContext, slug: string): Promise<string> {
	const resp = await request.get('/index.php/apps/openregister/api/registers?_limit=200', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(resp.status(), 'GET /apps/openregister/api/registers must answer 200').toBe(200)
	const body = await resp.json()
	const rows: Array<Record<string, unknown>> = body?.results ?? (Array.isArray(body) ? body : [])
	const hit = rows.find((r) => r.slug === slug)
	expect(hit, `register "${slug}" must exist on this instance`).toBeTruthy()
	return String(hit!.id)
}

/**
 * Navigate the SPA's real in-app hash route.
 *
 * A path-form `page.goto('/apps/opencatalogi/woo/<id>')` loads the SPA index
 * template and boots the router at `/`, so the Dashboard renders whatever path
 * was asked for — an assertion written on top of that passes vacuously.
 *
 * @param page The Playwright page.
 * @param route The in-app route, e.g. `/woo/<id>`.
 */
async function gotoHash(page: import('@playwright/test').Page, route: string): Promise<void> {
	await page.goto(`${APP}/#${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(1500)
	await dismissOverlays(page)
}

// ── src/views/catalogi/CatalogiIndex.vue ─────────────────────────────────────

test.describe('page component — CatalogiIndex', () => {
	/**
	 * The Catalogs page is a manifest `type: "custom"` page whose `component`
	 * is `CatalogsIndexView`, i.e. `src/views/catalogi/CatalogiIndex.vue`.
	 * Reaching it must produce that component's own header copy and its own
	 * add-label, neither of which any generic index page renders.
	 */
	test('CatalogiIndex renders its own index surface, header copy and Add Catalog CTA', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, '/catalogi')

		// The index host must be present…
		await expect(page.locator('[data-testid="cn-index-page"]').first())
			.toBeVisible({ timeout: 15000 })

		// …and it must be THIS component's index page, not some other one.
		await expect(content(page).getByText(CATALOGI_INDEX_DESCRIPTION).first())
			.toBeVisible({ timeout: 15000 })

		// CatalogiIndex.vue passes `:add-label="t('opencatalogi', 'Add Catalog')"`.
		await expect(page.locator('[data-testid="cn-cta-primary"]').first())
			.toBeVisible({ timeout: 10000 })
		await expect(page.locator('[data-testid="cn-cta-primary"]').first())
			.toContainText('Catalog', { timeout: 10000 })
	})

	/**
	 * CONTROL for the assertion above.
	 *
	 * `getByText(...)` resolving to nothing would satisfy any "is absent"
	 * assertion for free, and a page that failed to mount would satisfy it too.
	 * So the control is not "the text is missing somewhere" — it is that the
	 * SAME locator, on a DIFFERENT real page of the same app, resolves
	 * differently while the shared index host is present on both.
	 *
	 * Organizations is a manifest `type: "index"` page rendered by nc-vue's
	 * generic CnIndexPage from `src/manifest.json`, so it must show the index
	 * host and must NOT show CatalogiIndex's description.
	 */
	test('CatalogiIndex header copy is specific to it — a generic manifest index page does not render it', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, '/organizations')

		// Positive half: we really are on a rendered index page, not a blank
		// route. Without this the absence assertion below is worthless.
		await expect(page.locator('[data-testid="cn-index-page"]').first())
			.toBeVisible({ timeout: 15000 })

		// Negative half: same locator, same DOM, no match.
		await expect(content(page).getByText(CATALOGI_INDEX_DESCRIPTION))
			.toHaveCount(0)
	})
})

// ── src/views/directory/FederationDirectory.vue ──────────────────────────────

test.describe('page component — FederationDirectory', () => {
	/**
	 * `/directory` is a manifest `type: "custom"` page whose component is
	 * `FederationDirectory`. Its header, its three availability counters and its
	 * "Add directory" action are drawn by that component and nothing else.
	 */
	test('FederationDirectory renders its header, the three availability counters and the Add directory action', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, '/directory')

		const root = page.locator('.federation-directory')
		await expect(root).toBeVisible({ timeout: 20000 })
		await expect(root.locator('.federation-directory__title')).toHaveText('Directory')

		// The summary strip is this component's own structure: exactly the three
		// always-rendered buckets (a fourth, `unknown`, is `v-if`-gated on a
		// non-zero count and must not be present on a clean instance).
		const summary = root.locator('[data-testid="federation-directory-summary"]')
		await expect(summary).toBeVisible({ timeout: 15000 })
		await expect(summary).toContainText('available')
		await expect(summary).toContainText('degraded')
		await expect(summary).toContainText('unreachable')

		await expect(root.getByRole('button', { name: /add directory/i }).first())
			.toBeVisible({ timeout: 10000 })
	})

	/**
	 * CONTROL for the assertion above.
	 *
	 * `.federation-directory` is this component's root. On any other real page
	 * of the same app the shell, header and navigation are identical, so a
	 * locator that had silently stopped matching would look exactly like a
	 * passing absence assertion. Pairing it with a positive assertion on the
	 * OTHER page's own surface means only a genuinely page-scoped root can
	 * satisfy both halves.
	 */
	test('the FederationDirectory root is page-scoped — it is absent from the Catalogs page', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, '/catalogi')

		// Positive half: we are on a real, rendered page.
		await expect(page.locator('[data-testid="cn-index-page"]').first())
			.toBeVisible({ timeout: 15000 })

		// Negative half: same locator, same DOM, no match.
		await expect(page.locator('.federation-directory')).toHaveCount(0)
	})
})

// ── src/views/woo/WooBatchDetail.vue ─────────────────────────────────────────

test.describe('page component — WooBatchDetail', () => {
	/** The `wooBatch` object this describe block created, for cleanup. */
	let batchId = ''
	let objectsRoot = ''
	const caseReference = `${RUN_ID}-woo`

	test.beforeAll(async ({ playwright, baseURL, storageState }) => {
		const api = await playwright.request.newContext({ baseURL, storageState })
		const register = await registerId(api, 'publication')
		const schema = await schemaId(api, 'wooBatch')
		objectsRoot = `${OR_OBJECTS}/${register}/${schema}`

		const resp = await api.post(objectsRoot, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: {
				caseReference,
				status: 'draft',
				deckAvailable: false,
			},
		})
		expect(resp.status(), `seeding a wooBatch must succeed (got ${resp.status()})`).toBe(201)
		batchId = String((await resp.json()).id)
		expect(batchId, 'the seeded wooBatch must come back with an id').toBeTruthy()
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL, storageState }) => {
		if (!batchId) return
		const api = await playwright.request.newContext({ baseURL, storageState })
		await api.delete(`${objectsRoot}/${batchId}`, { headers: { 'OCS-APIRequest': 'true' } })
		await api.dispose()
	})

	/**
	 * `/woo/:id` is a manifest `type: "custom"` page whose `component` is
	 * `WooBatchDetailView`, i.e. `src/views/woo/WooBatchDetail.vue`.
	 *
	 * The heading interpolates the batch's own `caseReference`, which this run
	 * generated, so the assertion cannot be satisfied by a stale fixture, by
	 * another run's data, or by any other screen in the app.
	 */
	test('WooBatchDetail renders the batch heading, the four assessment counts and the Deck queue section', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, `/woo/${batchId}`)

		const root = page.locator('.woo-batch')
		await expect(root).toBeVisible({ timeout: 20000 })

		// This run's own case reference, in this component's own h2.
		await expect(root.locator('h2')).toContainText(caseReference, { timeout: 15000 })

		// The four WOO assessment buckets — structure only this component draws.
		const counts = root.locator('.woo-batch__counts li')
		await expect(counts).toHaveCount(4)
		await expect(root.locator('.woo-batch__counts')).toContainText('Te beoordelen')
		await expect(root.locator('.woo-batch__counts')).toContainText('Niet openbaar')

		// The Deck-board queue section.
		await expect(root.locator('.woo-batch__deck')).toBeVisible()
	})

	/**
	 * CONTROL for the assertion above.
	 *
	 * `.woo-batch` is the component's root and is rendered in BOTH the
	 * found and not-found states — what differs is what it contains. So a
	 * dead locator cannot produce this difference: the same root must show a
	 * heading carrying our case reference for a real id, and the component's
	 * "Batch not found" empty state for an id that cannot resolve.
	 */
	test('WooBatchDetail shows its not-found state for an unresolvable id, in the same root', async ({ page }) => {
		await bootApp(page)
		await gotoHash(page, '/woo/00000000-0000-0000-0000-000000000000')

		const root = page.locator('.woo-batch')
		await expect(root).toBeVisible({ timeout: 20000 })
		await expect(root).toContainText('Batch not found', { timeout: 20000 })
		await expect(root.locator('.woo-batch__counts li')).toHaveCount(0)
	})
})
