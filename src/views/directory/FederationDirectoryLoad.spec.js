/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `FederationDirectory.vue`'s load: the page it
 * requests, the listings and pagination it takes from the answer, and the
 * error it shows on failure. The method runs against a plain `this`, so no
 * component is mounted.
 */

import FederationDirectory from './FederationDirectory.vue'

jest.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }), { virtual: true })
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({ isAdmin: true }) }))
jest.mock('../../store/store.js', () => ({ navigationStore: {} }))

/**
 * A plain `this` on the given page.
 *
 * @param {object} state - The page state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { page: 3, limit: 10, pages: 9, total: 99, listings: ['old'], loading: false, error: 'old', ...state }
	for (const [name, fn] of Object.entries(FederationDirectory.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

afterEach(() => {
	delete global.fetch
})

describe('FederationDirectory load', () => {
	it('requests the page and takes the listings and pagination', async () => {
		global.fetch = jest.fn(async () => ({
			ok: true,
			json: async () => ({ results: [{ id: 'l1' }], page: 2, pages: 4, total: 31, limit: 25 }),
		}))
		const ctx = vm()
		await ctx.load()
		expect(global.fetch).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/listings?_page=3&_limit=10', {
			headers: { Accept: 'application/json' },
		})
		expect(ctx.listings).toEqual([{ id: 'l1' }])
		expect([ctx.page, ctx.pages, ctx.total, ctx.limit]).toEqual([2, 4, 31, 25])
		expect(ctx.error).toBe(null)
		expect(ctx.loading).toBe(false)
	})

	it('falls back to the first empty page for a bare answer', async () => {
		global.fetch = jest.fn(async () => ({ ok: true, json: async () => ({}) }))
		const ctx = vm()
		await ctx.load()
		expect(ctx.listings).toEqual([])
		expect([ctx.page, ctx.pages, ctx.total, ctx.limit]).toEqual([1, 1, 0, 20])
	})

	it.each([
		[async () => ({ ok: false, status: 503 }), 'HTTP 503'],
		[async () => {
			throw new Error('offline')
		}, 'offline'],
		[async () => {
			throw 'plain'
		}, 'plain'],
	])('shows a failure (%#)', async (fetchImpl, message) => {
		global.fetch = jest.fn(fetchImpl)
		const ctx = vm()
		await ctx.load()
		expect(ctx.error).toBe(message)
		expect(ctx.listings).toEqual([])
		expect([ctx.page, ctx.pages]).toEqual([3, 9])
		expect(ctx.loading).toBe(false)
	})
})
