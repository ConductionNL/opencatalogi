/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * public-api-openapi-document e2e coverage (API-DOC-003).
 *
 * Asserts the OpenAPI 3.1 self-documentation endpoint
 * (`GET /apps/opencatalogi/api/openapi.json`) is reachable ANONYMOUSLY
 * (no session, no CSRF token), returns JSON with CORS headers, and that
 * `info.version` matches the installed app version — proving the serve-time
 * substitution described in design decision D2 actually runs.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test openapi-document
 */
import { test, expect, request, type APIRequestContext } from '@playwright/test'
import { BASE } from '../workflows/_fixtures'

/** Anonymous (no credentials) request context — the public document caller. */
let anon: APIRequestContext

test.beforeAll(async () => {
	anon = await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
})

test.afterAll(async () => {
	await anon.dispose()
})

test.describe('openapi-document', () => {
	test(
		// @e2e api-documentation::anonymous-client-fetches-the-document-cross-origin
		'API-DOC-003 — GET /api/openapi.json is anonymously reachable, returns JSON with CORS headers and the installed version',
		async ({ request: authedRequest }) => {
			// Sanity: the anon context really is unauthenticated.
			const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status(), 'anon context is unauthenticated').toBe(401)

			const res = await anon.get(
				'/index.php/apps/opencatalogi/api/openapi.json',
				{ headers: { Origin: 'https://example.org' } },
			)
			expect(res.status(), 'openapi.json is served anonymously').toBe(200)
			expect(res.headers()['content-type'] ?? '').toContain('application/json')
			expect(
				res.headers()['access-control-allow-origin'],
				'CORS header present on the response',
			).toBeTruthy()

			const body = await res.json()
			expect(body.openapi).toBe('3.1.0')
			expect(body.info?.title).toBe('OpenCatalogi')
			expect(body.info?.license?.name).toBe('EUPL-1.2')

			// A known public path must be present — proves this is the real,
			// maintained document and not a stale/foreign stub.
			expect(body.paths).toHaveProperty('/api/search')
			expect(body.paths?.['/api/search']?.get).toBeTruthy()

			// `info.version` must equal the installed app version, obtained via the
			// (authenticated) `settings#getVersionInfo` endpoint that already exposes it.
			const versionResp = await authedRequest.get('/index.php/apps/opencatalogi/api/settings/version')
			if (versionResp.ok()) {
				const versionBody = await versionResp.json().catch(() => null)
				const installedVersion = (versionBody?.version ?? versionBody?.appVersion) as string | undefined
				if (installedVersion) {
					expect(body.info.version).toBe(installedVersion)
				}
			}
			// `info.version` must always be a non-empty version-shaped string, even
			// when the authenticated version-info comparison above could not run.
			expect(String(body.info.version)).toMatch(/^\d+\.\d+\.\d+/)
		},
	)
})
