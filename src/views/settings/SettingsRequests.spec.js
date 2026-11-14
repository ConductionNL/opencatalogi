/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the request methods of `Settings.vue`: configuration load,
 * version info, manual import, public cache time and channel sources, and the
 * "selected register has schemas" check.
 */

import axios from '@nextcloud/axios'
import Settings from './Settings.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn(), put: jest.fn() } }))
// @nextcloud/dialogs does not resolve under Jest in this tree.
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }), { virtual: true })
jest.mock('@nextcloud/dialogs/style.css', () => ({}), { virtual: true })
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('./PubliccodeHarvest.vue', () => ({}))
jest.mock('./WooIndexConnection.vue', () => ({}))

/**
 * A settings page state with its methods and `fields`.
 *
 * @param {object} fields State to set.
 * @return {object} The state.
 */
function page(fields = {}) {
	return {
		...Settings.methods,
		t: (app, text) => text,
		loadSettings: jest.fn(async () => {}),
		loadVersionInfo: jest.fn(async () => {}),
		...fields,
	}
}

beforeEach(() => {
	globalThis.fetch = jest.fn()
	axios.post.mockReset()
	axios.put.mockReset()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('Settings loadConfiguration', () => {
	it('reports success and reloads the settings', async () => {
		fetch.mockResolvedValue({ json: async () => ({}) })
		const state = page()
		await Settings.methods.loadConfiguration.call(state)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/load')
		expect(state.configurationResults).toEqual({ success: true })
		expect(state.loadSettings).toHaveBeenCalled()
		expect(state.loadingConfiguration).toBe(false)
	})

	it('reports the server error without reloading', async () => {
		fetch.mockResolvedValue({ json: async () => ({ error: 'E' }) })
		const state = page()
		await Settings.methods.loadConfiguration.call(state)

		expect(state.configurationResults).toEqual({ error: 'E' })
		expect(state.loadSettings).not.toHaveBeenCalled()
	})

	it('reports a failed request', async () => {
		fetch.mockRejectedValue(new Error('down'))
		const state = page()
		await Settings.methods.loadConfiguration.call(state)

		expect(state.configurationResults).toEqual({ error: 'Failed to load configuration: down' })
		expect(state.loadingConfiguration).toBe(false)
	})
})

describe('Settings loadVersionInfo', () => {
	it('stores the version info', async () => {
		fetch.mockResolvedValue({ json: async () => ({ version: '1' }) })
		const state = page({ loadingVersionInfo: true })
		await Settings.methods.loadVersionInfo.call(state)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/version')
		expect(state.versionInfo).toEqual({ version: '1' })
		expect(state.loadingVersionInfo).toBe(false)
	})

	it('logs a server error', async () => {
		fetch.mockResolvedValue({ json: async () => ({ error: 'E' }) })
		const state = page({ loadingVersionInfo: true, versionInfo: 'old' })
		await Settings.methods.loadVersionInfo.call(state)

		expect(state.versionInfo).toBe('old')
		expect(console.error).toHaveBeenCalledWith('Failed to load version info:', 'E')
		expect(state.loadingVersionInfo).toBe(false)
	})

	it('logs a failed request', async () => {
		const error = new Error('down')
		fetch.mockRejectedValue(error)
		const state = page({ loadingVersionInfo: true })
		await Settings.methods.loadVersionInfo.call(state)

		expect(console.error).toHaveBeenCalledWith('Failed to load version info:', error)
		expect(state.loadingVersionInfo).toBe(false)
	})
})

describe('Settings manualImport', () => {
	it.each([false, true])('imports with force %s and reloads after a success', async (force) => {
		axios.post.mockResolvedValue({ data: { success: true, message: 'ok' } })
		const state = page()
		await Settings.methods.manualImport.call(state, force)

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/import', { force })
		expect(state.importResult).toEqual({ success: true, message: 'ok' })
		expect(state.loadVersionInfo).toHaveBeenCalled()
		expect(state.loadSettings).toHaveBeenCalled()
		expect(state.importing).toBe(false)
	})

	it('defaults force to false', async () => {
		axios.post.mockResolvedValue({ data: { success: false } })
		const state = page()
		await Settings.methods.manualImport.call(state)

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/import', { force: false })
		expect(state.loadSettings).not.toHaveBeenCalled()
	})

	it('reports a failed import', async () => {
		axios.post.mockRejectedValue(new Error('down'))
		const state = page()
		await Settings.methods.manualImport.call(state)

		expect(state.importResult).toEqual({ success: false, message: 'Import failed: down' })
		expect(state.importing).toBe(false)
	})
})

describe('Settings savePublicCacheSeconds and saveChannelSources', () => {
	it.each([
		[{ data: { public_api_cache_seconds: 60 } }, '60'],
		[{ data: { public_api_cache_seconds: 0 } }, '0'],
		[{ data: {} }, '30'],
		// A null body is stored as the text 'null', as the page has always done.
		[{ data: null }, 'null'],
	])('stores what the server accepted from %j', async (response, shown) => {
		axios.put.mockResolvedValue(response)
		const state = page({ publicCacheSeconds: '30' })
		await Settings.methods.savePublicCacheSeconds.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { public_api_cache_seconds: '30' })
		expect(state).toMatchObject({ publicCacheSeconds: shown, publicCacheError: '', savingPublicCache: false })
	})

	it('reports a failed cache time save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({ publicCacheSeconds: '30' })
		await Settings.methods.savePublicCacheSeconds.call(state)

		expect(state).toMatchObject({ publicCacheError: 'The cache time could not be saved. Try again.', savingPublicCache: false })
	})

	it.each([
		[true, { data: { search_indexing: 'blocked' } }, 'blocked', true],
		[false, { data: { search_indexing: 'allowed' } }, 'allowed', false],
		[true, { data: { search_indexing: 'allowed' } }, 'blocked', false],
	])('saves search indexing %s and shows what the server stored', async (blocked, response, sent, shown) => {
		axios.put.mockResolvedValue(response)
		const state = page({ searchIndexingBlocked: blocked })
		await Settings.methods.saveSearchIndexing.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { search_indexing: sent })
		expect(state).toMatchObject({ searchIndexingBlocked: shown, searchIndexingError: '', savingSearchIndexing: false })
	})

	it('reports a failed search indexing save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({ searchIndexingBlocked: true })
		await Settings.methods.saveSearchIndexing.call(state)

		expect(state).toMatchObject({ searchIndexingError: 'The search indexing setting could not be saved. Try again.', savingSearchIndexing: false })
	})

	it('saves a copy of the channel sources', async () => {
		axios.put.mockResolvedValue({})
		const channelSources = { plooi: 'src' }
		const state = page({ channelSources })
		await Settings.methods.saveChannelSources.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { channel_sources: { plooi: 'src' } })
		expect(axios.put.mock.calls[0][1].channel_sources).not.toBe(channelSources)
		expect(state).toMatchObject({ channelSourcesError: '', savingChannelSources: false })
	})

	it('reports a failed channel sources save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({ channelSources: {} })
		await Settings.methods.saveChannelSources.call(state)

		expect(state).toMatchObject({ channelSourcesError: 'The channel sources could not be saved. Try again.', savingChannelSources: false })
	})
})

describe('Settings hasSchemas', () => {
	/**
	 * hasSchemas for a register list and a selection.
	 *
	 * @param {Array<object>} availableRegisters The registers.
	 * @param {object|null} selectedRegister The selection.
	 * @return {boolean} The answer.
	 */
	function has(availableRegisters, selectedRegister) {
		return Settings.computed.hasSchemas.call({ settings: { availableRegisters }, selectedRegister })
	}

	it.each([
		['no selection', [{ id: 1, schemas: [1] }], null, false],
		['an unknown register', [{ id: 1, schemas: [1] }], { value: '2' }, false],
		['schemas that are not a list', [{ id: 1, schemas: 'x' }], { value: '1' }, false],
		['no schemas', [{ id: 1, schemas: [] }], { value: '1' }, false],
		['schema ids', [{ id: 1, schemas: [5] }], { value: '1' }, true],
		['schema objects with id', [{ id: 1, schemas: [{ id: 5 }] }], { value: '1' }, true],
		['schema objects without id', [{ id: 1, schemas: [{}, null, 'x'] }], { value: '1' }, false],
	])('is right for %s', (label, registers, selected, expected) => {
		expect(has(registers, selected)).toBe(expected)
	})
})
