/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OOAPI-009 — the federation directory advertises a catalog's OOAPI 5.0 base
 * endpoint (openspec/specs/ooapi-catalog-publication/spec.md).
 *
 * WHY THIS COULD NOT BE WRITTEN BEFORE
 * -----------------------------------
 * `DirectoryService::convertCatalogToListing()` populates `ooapiEndpoint` only
 * when the catalog's `hasOoapi` is truthy. Until #847 the `catalog` schema did
 * not declare `hasOoapi` at all, so OpenRegister's `hardValidation` stripped the
 * key on every write and the flag could never be true on any instance. A test
 * written then would have asserted an absence that was guaranteed for the wrong
 * reason — the endpoint was missing because the feature was unreachable, not
 * because the catalog had opted out. That is precisely the failure mode this
 * suite exists to prevent, so the scenario was left uncovered until the schema
 * was fixed.
 *
 * HOW THIS TEST AVOIDS THE SAME TRAP
 * ----------------------------------
 * A single "the endpoint is advertised" assertion could pass on a lucky fixture,
 * and a single "it is not advertised" assertion is satisfied for free by a
 * broken lookup, a mistyped field name, or an empty directory. So the two
 * catalogs below differ in EXACTLY ONE field — `hasOoapi` — are created in the
 * same run, and are read out of the SAME `/api/directory` response by the same
 * locator. A selector that had stopped matching, or a field name that had been
 * renamed, cannot produce a difference between them; only the OOAPI-009 rule
 * actually working can.
 *
 * FIXTURES ARE MINTED, NOT BORROWED. The instance's own `publications` catalog
 * is deliberately untouched: `spec-coverage/ooapi.spec.ts` asserts that a
 * catalog with OOAPI switched off refuses every OOAPI route, and flipping a
 * shared catalog's flag would make that suite's result a function of test order.
 *
 * Run:
 *   PLAYWRIGHT_BASE_URL=http://localhost:8296 \
 *     npx playwright test --config=tests/e2e/playwright.config.ts spec-coverage/ooapi-directory
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const APP = '/index.php/apps/opencatalogi'
const OR = '/index.php/apps/openregister/api'

/** Run-scoped marker so a leaked fixture can never satisfy another run's assertions. */
const RUN = `ooapi-adv-${Date.now()}`

const ENABLED_SLUG = `${RUN}-on`
const DISABLED_SLUG = `${RUN}-off`

/** Ids created by this file, torn down in afterAll. */
const created: string[] = []
let catalogObjects = ''

/**
 * Resolve the numeric id of an OpenRegister register or schema by slug.
 *
 * Ids are assigned at import time and differ per instance; hardcoding one makes
 * a suite pass or fail on the ordering of a register import.
 *
 * @param api An authenticated API request context.
 * @param kind Either `registers` or `schemas`.
 * @param slug The slug to resolve.
 * @return The numeric id, as a string.
 */
async function idFor(api: APIRequestContext, kind: 'registers' | 'schemas', slug: string): Promise<string> {
	const resp = await api.get(`${OR}/${kind}?_limit=200&limit=200`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(resp.status(), `GET ${OR}/${kind} must answer 200`).toBe(200)
	const body = await resp.json()
	const rows: Array<Record<string, unknown>> = body?.results ?? (Array.isArray(body) ? body : [])
	const hit = rows.find((r) => r.slug === slug)
	expect(hit, `${kind.slice(0, -1)} "${slug}" must exist — the register import is a precondition`).toBeTruthy()
	return String(hit!.id)
}

/**
 * Create a catalog fixture.
 *
 * @param api An authenticated API request context.
 * @param slug The catalog slug.
 * @param hasOoapi Whether OOAPI 5.0 publication is enabled for it.
 * @return The created catalog's id.
 */
async function makeCatalog(api: APIRequestContext, slug: string, hasOoapi: boolean): Promise<string> {
	const resp = await api.post(catalogObjects, {
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: {
			title: slug,
			slug,
			summary: `OOAPI-009 fixture (hasOoapi=${hasOoapi})`,
			listed: true,
			status: 'stable',
			hasOoapi,
		},
	})
	expect(resp.status(), `creating catalog "${slug}" must succeed (got ${resp.status()})`).toBe(201)
	const id = String((await resp.json()).id)
	created.push(id)
	return id
}

/**
 * Fetch the federation directory listings.
 *
 * @param api An authenticated API request context.
 * @return The listing rows.
 */
async function directoryListings(api: APIRequestContext): Promise<Array<Record<string, any>>> {
	const resp = await api.get(`${APP}/api/directory`, { headers: { 'OCS-APIRequest': 'true' } })
	expect(resp.status(), 'GET /api/directory must answer 200').toBe(200)
	const body = await resp.json()
	return body?.results ?? (Array.isArray(body) ? body : [])
}

/**
 * Find this run's listing for a catalog id.
 *
 * @param rows The directory listing rows.
 * @param id The catalog id.
 * @return The matching row, or undefined.
 */
function listingFor(rows: Array<Record<string, any>>, id: string): Record<string, any> | undefined {
	return rows.find((r) => String(r.catalog ?? r.id ?? '') === id)
}

test.describe('OOAPI 5.0 federation directory advertisement', () => {
	let enabledId = ''
	let disabledId = ''

	test.beforeAll(async ({ playwright, baseURL, storageState }) => {
		const api = await playwright.request.newContext({ baseURL, storageState })
		const register = await idFor(api, 'registers', 'publication')
		const schema = await idFor(api, 'schemas', 'catalog')
		catalogObjects = `${OR}/objects/${register}/${schema}`

		// PRECONDITION, asserted loudly: `hasOoapi` must survive a write at all.
		// Before #847 it did not, and every assertion below would then have
		// passed or failed for a reason unrelated to OOAPI-009.
		enabledId = await makeCatalog(api, ENABLED_SLUG, true)
		const readBack = await api.get(`${catalogObjects}/${enabledId}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(readBack.status(), 'reading the fixture back must answer 200').toBe(200)
		expect(
			(await readBack.json()).hasOoapi,
			'PRECONDITION: the catalog schema must declare `hasOoapi` (#847). If this is not true, '
			+ 'OpenRegister strips the key and nothing below is a statement about OOAPI-009.',
		).toBe(true)

		disabledId = await makeCatalog(api, DISABLED_SLUG, false)
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL, storageState }) => {
		const api = await playwright.request.newContext({ baseURL, storageState })
		for (const id of created.reverse()) {
			await api.delete(`${catalogObjects}/${id}`, { headers: { 'OCS-APIRequest': 'true' } })
		}
		await api.dispose()
	})

	test(
		// @e2e ooapi-catalog-publication::directory-entry-carries-the-ooapi-base-url
		'OOAPI-009 — an OOAPI-enabled catalog advertises an absolute ooapiEndpoint, and an identical disabled one advertises none',
		async ({ playwright, baseURL, storageState }) => {
			const api = await playwright.request.newContext({ baseURL, storageState })
			const rows = await directoryListings(api)

			// Both fixtures must be IN the directory. Without this, "the disabled
			// one has no ooapiEndpoint" would be satisfied by it simply being
			// absent from the response.
			const on = listingFor(rows, enabledId)
			const off = listingFor(rows, disabledId)
			expect(on, `the OOAPI-enabled fixture ${ENABLED_SLUG} must be listed in the directory`).toBeTruthy()
			expect(off, `the disabled fixture ${DISABLED_SLUG} must ALSO be listed — the difference must be the endpoint, not the listing`).toBeTruthy()

			// The requirement: an ABSOLUTE base URL for that catalog's v5 resources.
			expect(on!.ooapiEndpoint, 'the enabled catalog must advertise ooapiEndpoint').toBeTruthy()
			expect(String(on!.ooapiEndpoint)).toMatch(/^https?:\/\//)
			expect(String(on!.ooapiEndpoint)).toContain(`/api/catalogs/${ENABLED_SLUG}/ooapi/v5`)

			// The control: same response, same field, same accessor — the ONLY
			// difference between the two catalogs is `hasOoapi`.
			expect(
				off!.ooapiEndpoint ?? null,
				'a catalog with hasOoapi=false must advertise no OOAPI endpoint at all',
			).toBeNull()

			await api.dispose()
		},
	)
})
