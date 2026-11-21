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
import {
	corsOriginLines,
	corsSaveRefusal,
	languageName,
	liftGroupsPayload,
	liftGroupsText,
	publicationLanguageOptions,
	publicationLanguagesFrom,
	publicationLanguagesPayload,
} from './settingsRequests.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn(), put: jest.fn() } }))
// @nextcloud/dialogs does not resolve under Jest in this tree.
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }), { virtual: true })
jest.mock('@nextcloud/dialogs/style.css', () => ({}), { virtual: true })
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('./PubliccodeHarvest.vue', () => ({}))
jest.mock('./WooIndexConnection.vue', () => ({}))
jest.mock('./DonlHarvestStatus.vue', () => ({}))

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

describe('Settings publication languages (publication-translations D6)', () => {
	it.each([
		['["nl","en","de"]', ['nl', 'en', 'de']],
		['["de","nl"]', ['nl', 'de']],
		[['fy', 'fy', ''], ['nl', 'fy']],
		['not json', ['nl', 'en']],
		['[]', ['nl', 'en']],
		[undefined, ['nl', 'en']],
	])('reads %j as %j', (stored, expected) => {
		expect(publicationLanguagesFrom(stored)).toEqual(expected)
	})

	it('names a language in the reader\'s language and falls back to the tag', () => {
		expect(languageName('de', 'en')).toBe('German')
		expect(languageName('!!', 'en')).toBe('!!')
	})

	it('offers every language, a stored unknown one too, with Dutch locked', () => {
		const options = publicationLanguageOptions(['nl', 'sv'], 'en')

		expect(options[0]).toEqual({ id: 'nl', label: 'Dutch', locked: true })
		expect(options.find((option) => option.id === 'en')).toEqual({ id: 'en', label: 'English', locked: false })
		expect(options[options.length - 1].id).toBe('sv')
	})

	it('saves the selected options with Dutch first', () => {
		expect(publicationLanguagesPayload([{ id: 'en' }, { id: 'de' }])).toEqual(['nl', 'en', 'de'])
		expect(publicationLanguagesPayload([])).toEqual(['nl', 'en'])
	})

	it('takes the languages from the stored configuration', () => {
		const state = page({ channelSources: {}, parseChannelSources: () => ({}) })
		Settings.methods.applyStoredConfiguration.call(state, { publication_languages: '["nl","fr"]' })

		expect(state.publicationLanguages).toEqual(['nl', 'fr'])
	})

	it('saves the languages and shows what the server stored', async () => {
		axios.put.mockResolvedValue({ data: { publication_languages: '["nl","de"]' } })
		const state = page({ publicationLanguages: ['nl', 'de', 'xx'] })
		await Settings.methods.savePublicationLanguages.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { publication_languages: ['nl', 'de', 'xx'] })
		expect(state).toMatchObject({ publicationLanguages: ['nl', 'de'], publicationLanguagesError: '', savingPublicationLanguages: false })
	})

	it('reports a failed languages save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({ publicationLanguages: ['nl'] })
		await Settings.methods.savePublicationLanguages.call(state)

		expect(state).toMatchObject({ publicationLanguagesError: 'The languages could not be saved. Try again.', savingPublicationLanguages: false })
	})

	it('selects and writes the languages through the select', () => {
		const state = { publicationLanguages: ['nl', 'en'] }
		state.publicationLanguageChoices = Settings.computed.publicationLanguageChoices.call(state)
		const selection = Settings.computed.publicationLanguageSelection

		expect(selection.get.call(state).map((option) => option.id)).toEqual(['nl', 'en'])
		selection.set.call(state, [{ id: 'de' }])
		expect(state.publicationLanguages).toEqual(['nl', 'de'])
	})
})

describe('Settings saveCorsOrigins (REQ-SCO-003)', () => {
	it('saves the lines and shows back what the server stored', async () => {
		axios.put.mockResolvedValue({ data: { cors_allowed_origins: 'https://a.example,https://b.example' } })
		const state = page({ corsOrigins: 'https://a.example\nhttps://b.example/' })
		await Settings.methods.saveCorsOrigins.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { cors_allowed_origins: 'https://a.example\nhttps://b.example/' })
		expect(state).toMatchObject({ corsOrigins: 'https://a.example\nhttps://b.example', corsError: '', savingCors: false })
	})

	it('shows the server naming a refused line', async () => {
		axios.put.mockRejectedValue({ response: { status: 400, data: { error: '"https://a.example/zoeken" is not a website origin.' } } })
		const state = page({ corsOrigins: 'https://a.example/zoeken' })
		await Settings.methods.saveCorsOrigins.call(state)

		expect(state).toMatchObject({ corsOrigins: 'https://a.example/zoeken', corsError: '"https://a.example/zoeken" is not a website origin.', savingCors: false })
	})

	it('reports any other failed save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({ corsOrigins: '*' })
		await Settings.methods.saveCorsOrigins.call(state)

		expect(state).toMatchObject({ corsError: 'The websites could not be saved. Try again.', savingCors: false })
	})

	it.each([
		['https://a.example,https://b.example', 'https://a.example\nhttps://b.example'],
		[' https://a.example , ', 'https://a.example'],
		['*', '*'],
		['', '*'],
		[undefined, '*'],
	])('shows the stored allowlist %j as lines', (stored, lines) => {
		expect(corsOriginLines(stored)).toBe(lines)
	})

	it.each([
		[{ response: { status: 500, data: { error: 'boom' } } }, ''],
		[{ response: { status: 400, data: {} } }, ''],
		[new Error('down'), ''],
		[undefined, ''],
	])('names no reason for %j', (error, reason) => {
		expect(corsSaveRefusal(error)).toBe(reason)
	})

	it('loads the stored allowlist into the field', () => {
		const state = page({ channelSources: {}, parseChannelSources: () => ({}) })
		Settings.methods.applyStoredConfiguration.call(state, { cors_allowed_origins: 'https://a.example,https://b.example' })

		expect(state.corsOrigins).toBe('https://a.example\nhttps://b.example')
	})
})

describe('Settings embargo lift groups (REQ-PSG-002)', () => {
	it.each([
		['["admin","griffie"]', 'admin, griffie'],
		['[" griffie ", 7, ""]', 'griffie'],
		['[]', 'admin'],
		['not json', 'admin'],
		[undefined, 'admin'],
		['{"a":"b"}', 'admin'],
	])('shows the stored groups %j as %j', (stored, shown) => {
		expect(liftGroupsText(stored)).toBe(shown)
	})

	it.each([
		['admin, griffie ,admin,, ', ['admin', 'griffie']],
		['', []],
		[undefined, []],
	])('saves the text %j as the group ids %j', (text, ids) => {
		expect(liftGroupsPayload(text)).toEqual(ids)
	})

	it('takes the groups from the stored configuration', () => {
		const state = page({
			liftGroups: { text: 'admin', saving: false, error: '' },
		})
		Settings.methods.applyStoredConfiguration.call(state, {
			publication_embargo_lift_groups: '["griffie"]',
		})

		expect(state.liftGroups).toEqual({
			text: 'griffie',
			saving: false,
			error: '',
		})
	})

	it('saves the groups and shows what the server stored', async () => {
		axios.put.mockResolvedValue({
			data: { publication_embargo_lift_groups: '["griffie","admin"]' },
		})
		const state = page({
			liftGroups: {
				text: 'griffie, admin, griffie',
				saving: false,
				error: '',
			},
		})
		await Settings.methods.saveLiftGroups.call(state)

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', {
			publication_embargo_lift_groups: ['griffie', 'admin'],
		})
		expect(state.liftGroups).toEqual({
			text: 'griffie, admin',
			saving: false,
			error: '',
		})
	})

	it('keeps the field when the server sends nothing back', async () => {
		axios.put.mockResolvedValue({})
		const state = page({
			liftGroups: { text: 'griffie', saving: false, error: '' },
		})
		await Settings.methods.saveLiftGroups.call(state)

		expect(state.liftGroups).toEqual({
			text: 'griffie',
			saving: false,
			error: '',
		})
	})

	it('reports a failed save', async () => {
		axios.put.mockRejectedValue(new Error('down'))
		const state = page({
			liftGroups: { text: 'griffie', saving: false, error: '' },
		})
		await Settings.methods.saveLiftGroups.call(state)

		expect(state.liftGroups).toEqual({
			text: 'griffie',
			saving: false,
			error: 'The groups could not be saved. Try again.',
		})
	})
})
