/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OOAPI 5.0 access-control and fail-closed coverage
 * (openspec/specs/ooapi-catalog-publication/spec.md).
 *
 * SCOPE, STATED HONESTLY. The ooapi-catalog-publication spec declares 20
 * scenarios. This file covers the FOUR that can be asserted against a real
 * instance without first building an OOAPI content fixture: the auth gate,
 * the unknown-resource gate, and the two "OOAPI is switched off for this
 * catalog" gates. The remaining sixteen describe CONTENT behaviour — RIO
 * identifiers, annotated-schema mapping, pagination across a large course
 * list, a Scholiq course appearing and then disappearing after sync — and
 * each needs an OOAPI-enabled catalog whose schemas carry the OOAPI
 * annotations plus seeded course/offering/programme objects. Writing those
 * as assertions against an instance that has no such content would produce
 * tests that pass because nothing is there, which is the failure mode this
 * whole suite exists to prevent. They are left uncovered deliberately, and
 * gate-19 still reports them.
 *
 * The four below are the load-bearing ones for a PUBLIC endpoint: they are
 * what stops an unconfigured or switched-off instance serving educational
 * data to an anonymous caller.
 *
 * Verified against the dev instance before being written, not assumed:
 *   anonymous            -> 401
 *   unknown catalog slug -> 404
 *   OOAPI-disabled catalog -> 404 {"error":"OOAPI 5.0 publication is not
 *                                   enabled for this catalog"}
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test spec-coverage/ooapi
 */
import { test, expect, request, type APIRequestContext } from '@playwright/test'

const APP = '/index.php/apps/opencatalogi'

/** A slug that cannot exist, for the unknown-resource gate. */
const UNKNOWN_SLUG = 'definitely-not-a-catalog-slug'

/**
 * Every OOAPI v5 collection endpoint. A catalog with OOAPI switched off must
 * advertise NOTHING — asserting only `/courses` would let a regression that
 * re-opened `/programs` or `/organizations` through unnoticed.
 */
const OOAPI_COLLECTIONS = [
	'organizations',
	'programs',
	'courses',
]

/** Anonymous context: an explicitly empty cookie jar. */
let anon: APIRequestContext

test.beforeAll(async ({ playwright }, testInfo) => {
	// The dev container leaks an admin session into a bare request context,
	// so a context that merely omits credentials is NOT anonymous. The empty
	// storageState is what makes the 401 assertions meaningful — without it
	// they would be asserting the admin's own response.
	anon = await request.newContext({
		baseURL: testInfo.project.use.baseURL as string,
		storageState: { cookies: [], origins: [] },
	})
})

test.afterAll(async () => {
	await anon.dispose()
})

test.describe('OOAPI 5.0 access control', () => {

	/**
	 * OOAPI-008. The endpoints carry consumer-credential auth; an anonymous
	 * caller must never reach educational data.
	 */
	test(
		// @e2e ooapi-catalog-publication::anonymous-request-is-rejected
		'OOAPI — an anonymous request is rejected with 401',
		async () => {
			for (const collection of OOAPI_COLLECTIONS) {
				const resp = await anon.get(`${APP}/api/catalogs/publications/ooapi/v5/${collection}`)
				expect(
					resp.status(),
					`anonymous GET /ooapi/v5/${collection} must be refused, not served`,
				).toBe(401)
			}
		},
	)

	/**
	 * The auth gate must run BEFORE the catalog lookup. If it did not, an
	 * anonymous caller would be able to tell an existing catalog from a
	 * missing one by the status code — 404 vs 401 — which is a slug oracle.
	 */
	test(
		// @e2e ooapi-catalog-publication::anonymous-request-is-rejected
		'OOAPI — anonymous gets 401 for an unknown slug too, not a 404 oracle',
		async () => {
			const resp = await anon.get(`${APP}/api/catalogs/${UNKNOWN_SLUG}/ooapi/v5/courses`)
			expect(
				resp.status(),
				'an anonymous caller must not be able to probe which catalog slugs exist',
			).toBe(401)
		},
	)

	/**
	 * An authenticated, allowed consumer passes the credential gate. Asserted
	 * as "not 401/403" rather than "200", because whether content comes back
	 * depends on catalog configuration — conflating the two would make this
	 * test fail for the wrong reason on an instance with no OOAPI catalog.
	 */
	test(
		// @e2e ooapi-catalog-publication::authenticated-consumer-with-a-valid-credential-succeeds
		'OOAPI — an authenticated allowed consumer passes the credential gate',
		async ({ request: authed }) => {
			const resp = await authed.get(`${APP}/api/catalogs/publications/ooapi/v5/courses`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(
				[401, 403],
				`an authenticated consumer must clear the credential gate (got ${resp.status()})`,
			).not.toContain(resp.status())
		},
	)

	test(
		// @e2e ooapi-catalog-publication::unknown-catalog-slug-or-resource-id
		'OOAPI — an unknown catalog slug is a 404 for an authenticated consumer',
		async ({ request: authed }) => {
			const resp = await authed.get(`${APP}/api/catalogs/${UNKNOWN_SLUG}/ooapi/v5/courses`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(resp.status()).toBe(404)
		},
	)

	test(
		// @e2e ooapi-catalog-publication::unknown-catalog-slug-or-resource-id
		'OOAPI — an unknown resource id inside a real catalog is a 404',
		async ({ request: authed }) => {
			const resp = await authed.get(
				`${APP}/api/catalogs/publications/ooapi/v5/courses/00000000-0000-0000-0000-000000000000`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(resp.status()).toBe(404)
		},
	)

	/**
	 * OOAPI is opt-in per catalog. A catalog that has not enabled it must not
	 * serve OOAPI at all — this is the switch that keeps educational-data
	 * endpoints closed on every catalog that never asked for them.
	 */
	test(
		// @e2e ooapi-catalog-publication::ooapi-disabled-for-a-catalog
		'OOAPI — a catalog without OOAPI enabled refuses with a named reason',
		async ({ request: authed }) => {
			const resp = await authed.get(`${APP}/api/catalogs/publications/ooapi/v5/courses`, {
				headers: { 'OCS-APIRequest': 'true' },
			})

			expect(resp.status()).toBe(404)
			const body = await resp.json()
			// The REASON is asserted, not just the code. A 404 from a missing
			// route and a 404 from "OOAPI is switched off" are the same byte,
			// and only one of them means the feature gate is working.
			expect(body.error).toContain('not enabled for this catalog')
		},
	)

	test(
		// @e2e ooapi-catalog-publication::disabled-catalog-advertises-nothing
		'OOAPI — a disabled catalog advertises nothing on ANY collection',
		async ({ request: authed }) => {
			for (const collection of OOAPI_COLLECTIONS) {
				const resp = await authed.get(
					`${APP}/api/catalogs/publications/ooapi/v5/${collection}`,
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				expect(
					resp.status(),
					`/ooapi/v5/${collection} must stay closed while OOAPI is disabled for the catalog`,
				).toBe(404)
			}
		},
	)
})
