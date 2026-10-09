/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public API's cache headers (operations-public-api-cache-headers).
 *
 * An anonymous search answer is public with an ETag, a matching
 * If-None-Match answers 304, a signed-in answer is private, and a cache time
 * of 0 switches the public headers off. The setting is restored afterwards.
 *
 * @e2e openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#scenario-a-cdn-caches-a-catalogue-page
 * @e2e openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#scenario-nothing-changed
 * @e2e openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#scenario-an-editor-searches
 * @e2e openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#scenario-an-administrator-switches-caching-off
 */
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const SEARCH = `${API_BASE}/search?_search=e2e-cache-probe`

test.describe('Public API cache headers', () => {
	let before = '60'

	test.beforeAll(async ({ request: admin }) => {
		const settings = await (await admin.get(`${API_BASE}/settings`)).json()
		before = String(settings?.configuration?.public_api_cache_seconds ?? '60')
		const set = await admin.put(`${API_BASE}/settings`, { data: { public_api_cache_seconds: 60 } })
		expect(set.ok()).toBe(true)
	})

	test.afterAll(async ({ request: admin }) => {
		await admin.put(`${API_BASE}/settings`, { data: { public_api_cache_seconds: before } })
	})

	test('an anonymous answer is public, and an unchanged one answers 304', async ({ baseURL }) => {
		const anonymous = await playwrightRequest.newContext({ baseURL })
		const first = await anonymous.get(SEARCH)
		expect(first.status()).toBe(200)
		expect(first.headers()['cache-control']).toContain('public, max-age=60')
		expect(first.headers().vary).toContain('Origin')
		const etag = first.headers().etag
		expect(etag).toMatch(/^W\/".+"$/)

		const second = await anonymous.get(SEARCH, { headers: { 'If-None-Match': etag } })
		expect(second.status()).toBe(304)
		await anonymous.dispose()
	})

	test('a signed-in answer stays private', async ({ request: admin }) => {
		const answer = await admin.get(SEARCH)
		expect(answer.headers()['cache-control']).toContain('private')
		expect(answer.headers().etag).toBeUndefined()
	})

	test('a cache time of 0 switches the public headers off', async ({ request: admin, baseURL }) => {
		expect((await admin.put(`${API_BASE}/settings`, { data: { public_api_cache_seconds: 0 } })).ok()).toBe(true)
		const anonymous = await playwrightRequest.newContext({ baseURL })
		const answer = await anonymous.get(SEARCH)
		expect(answer.status()).toBe(200)
		expect(answer.headers()['cache-control'] ?? '').not.toContain('public')
		expect(answer.headers().etag).toBeUndefined()
		await anonymous.dispose()
	})
})
