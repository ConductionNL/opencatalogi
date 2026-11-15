/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the admin settings page (`Settings.vue`): the
 * Woo readiness check, loading the sync options and saving the register
 * and schema configuration. Each method runs against a plain `this`, so no
 * component is mounted.
 */

import Settings from './Settings.vue'

jest.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }), { virtual: true })
jest.mock('@nextcloud/dialogs/style.css', () => ({}), { virtual: true })
jest.mock('@nextcloud/axios', () => ({ post: jest.fn(), put: jest.fn(), get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))

const axios = jest.requireMock('@nextcloud/axios')

/**
 * A plain `this` with the given state and every method of the page.
 *
 * @param {object} state - The page state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { t: (app, text) => `[${text}]`, ...state }
	for (const [name, fn] of Object.entries(Settings.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	axios.post.mockReset()
	axios.put.mockReset()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
	delete global.fetch
})

describe('Settings runWooReadinessCheck', () => {
	it('keeps the report', async () => {
		axios.post.mockResolvedValue({ data: { checks: [] } })
		const ctx = vm({ wooReadinessError: 'old' })
		await ctx.runWooReadinessCheck()
		expect(axios.post).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/woo/readiness/run')
		expect(ctx.wooReadinessReport).toEqual({ checks: [] })
		expect(ctx.wooReadinessError).toBe(null)
		expect(ctx.wooReadinessRunning).toBe(false)
	})

	it.each([
		[{ error: 'not-configured' }, '[No Woo-enabled catalog is configured yet — enable Woo sitemaps on a catalog first.]'],
		[{ error: 'other' }, 'other'],
		[{}, '[Readiness check failed.]'],
		[null, undefined],
	])('shows the refusal body %j', async (data, message) => {
		axios.post.mockRejectedValue({ response: { data } })
		const ctx = vm()
		if (data === null) {
			await expect(ctx.runWooReadinessCheck()).rejects.toThrow(TypeError)
		} else {
			await ctx.runWooReadinessCheck()
			expect(ctx.wooReadinessError).toBe(message)
			expect(console.error).not.toHaveBeenCalled()
		}
		expect(ctx.wooReadinessRunning).toBe(false)
	})

	it('logs a failure without a body', async () => {
		axios.post.mockRejectedValue(new Error('offline'))
		const ctx = vm()
		await ctx.runWooReadinessCheck()
		expect(ctx.wooReadinessError).toBe('[Readiness check failed.]')
		expect(console.error).toHaveBeenCalledWith('Failed to run Woo readiness check:', expect.any(Error))
	})
})

describe('Settings loadSyncOptions', () => {
	it('shows the intervals in minutes', async () => {
		global.fetch = jest.fn(async () => ({
			json: async () => ({ sync_interval_seconds: 1830, min_interval_seconds: 300, max_interval_seconds: 86400, default_interval_seconds: 3600 }),
		}))
		const ctx = vm()
		await ctx.loadSyncOptions()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/sync', { headers: { 'OCS-APIRequest': 'true' } })
		expect(ctx.syncOptions).toEqual({ intervalMinutes: 31, minMinutes: 5, maxMinutes: 1440, defaultMinutes: 60 })
		expect(ctx.loadingSyncOptions).toBe(false)
	})

	it('keeps the options on an error answer and logs a failure', async () => {
		global.fetch = jest.fn(async () => ({ json: async () => ({ error: 'no' }) }))
		const ctx = vm({ syncOptions: 'kept' })
		await ctx.loadSyncOptions()
		expect(ctx.syncOptions).toBe('kept')
		global.fetch = jest.fn(async () => {
			throw new Error('down')
		})
		await ctx.loadSyncOptions()
		expect(console.error).toHaveBeenCalledWith('Failed to load sync options:', expect.any(Error))
		expect(ctx.loadingSyncOptions).toBe(false)
	})
})

describe('Settings saveAll', () => {
	it('stores every object type with the register and its schema', async () => {
		axios.put.mockResolvedValue({})
		const ctx = vm({
			selectedRegister: { value: '5' },
			hasSchemas: true,
			configuration: { catalog: { schema: { value: '12' } }, theme: { schema: null } },
		})
		await ctx.saveAll()
		expect(axios.put).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/settings', {
			catalog_source: 'openregister',
			catalog_register: '5',
			catalog_schema: '12',
			theme_source: 'openregister',
			theme_register: '5',
			theme_schema: '',
		})
		expect(Object.keys(axios.put.mock.calls[0][1])).toEqual([
			'catalog_source', 'catalog_register', 'catalog_schema', 'theme_source', 'theme_register', 'theme_schema',
		])
		expect(ctx.saving).toBe(false)
	})

	it.each([
		[{ selectedRegister: null, hasSchemas: true }],
		[{ selectedRegister: { value: '5' }, hasSchemas: false }],
	])('saves nothing for %j', async (state) => {
		const ctx = vm({ configuration: {}, ...state })
		await ctx.saveAll()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('logs a failed save', async () => {
		axios.put.mockRejectedValue(new Error('denied'))
		const ctx = vm({ selectedRegister: { value: '5' }, hasSchemas: true, configuration: {} })
		await ctx.saveAll()
		expect(console.error).toHaveBeenCalledWith('Failed to save settings:', expect.any(Error))
		expect(ctx.saving).toBe(false)
	})
})
