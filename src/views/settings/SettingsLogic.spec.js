/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the admin settings page (`Settings.vue`): how
 * the configuration is initialised from the stored settings, which register
 * and schemas are picked automatically, the remediation hint of a check
 * reason, and the load, save and sync actions. Each method runs against a
 * plain `this`, so no component is mounted.
 */

import Settings from './Settings.vue'

const mockToasts = []

jest.mock('@nextcloud/dialogs', () => ({
	showError: (message) => mockToasts.push(['error', message]),
	showSuccess: (message) => mockToasts.push(['success', message]),
}), { virtual: true })

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
	const ctx = {
		configuration: {},
		selectedRegister: null,
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		...state,
	}
	for (const [name, fn] of Object.entries(Settings.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	ctx.updateSchemaOptions = jest.fn()
	return ctx
}

beforeEach(() => {
	mockToasts.length = 0
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
	delete global.fetch
})

describe('Settings initializeConfiguration', () => {
	it('selects the configured register and the listed schema object', () => {
		const ctx = vm({
			settings: {
				objectTypes: ['catalog', 'listing'],
				configuration: { catalog_register: '5', catalog_schema: '12', listing_schema: '13' },
				availableRegisters: [
					{ id: 4, title: 'Other', schemas: [] },
					{ id: 5, title: 'Pub', schemas: [null, { id: 11, title: 'A' }, { id: 12, title: 'B' }, { id: 13 }] },
				],
			},
		})
		ctx.initializeConfiguration()
		expect(ctx.selectedRegister).toEqual({ label: 'Pub', value: '5' })
		expect(ctx.updateSchemaOptions).toHaveBeenCalledWith('5')
		expect(ctx.configuration).toEqual({
			catalog: { schema: { label: 'B', value: '12' } },
			listing: { schema: { label: 'Schema 13', value: '13' } },
		})
	})

	it('keeps an already selected register and resolves numeric schema ids', () => {
		const ctx = vm({
			selectedRegister: { label: 'R', value: '5' },
			settings: {
				objectTypes: ['catalog', 'theme', 'page'],
				configuration: { catalog_register: '9', catalog_schema: '3', theme_schema: '8' },
				availableRegisters: [{ id: 5, title: 'R', schemas: [3, 4] }],
			},
		})
		ctx.initializeConfiguration()
		expect(ctx.selectedRegister).toEqual({ label: 'R', value: '5' })
		expect(ctx.updateSchemaOptions).not.toHaveBeenCalled()
		expect(ctx.configuration).toEqual({
			catalog: { schema: { label: 'Schema 3', value: '3' } },
			theme: { schema: { label: 'Schema 8', value: '8' } },
			page: { schema: null },
		})
	})

	it('leaves the schema empty when the register is unknown or lists no schemas', () => {
		const ctx = vm({
			selectedRegister: { label: 'R', value: '77' },
			settings: {
				objectTypes: ['catalog'],
				configuration: { catalog_schema: '3' },
				availableRegisters: [{ id: 5, title: 'R', schemas: [3] }],
			},
		})
		ctx.initializeConfiguration()
		expect(ctx.configuration).toEqual({ catalog: { schema: null } })

		const noSchemas = vm({
			selectedRegister: { label: 'R', value: '5' },
			settings: {
				objectTypes: ['catalog'],
				configuration: { catalog_schema: '3' },
				availableRegisters: [{ id: 5, title: 'R' }],
			},
		})
		noSchemas.initializeConfiguration()
		expect(noSchemas.configuration).toEqual({ catalog: { schema: null } })
	})

	it('does not select a configured register that is not available', () => {
		const ctx = vm({
			settings: {
				objectTypes: ['catalog'],
				configuration: { catalog_register: '9', catalog_schema: '3' },
				availableRegisters: [{ id: 5, title: 'R', schemas: [3] }],
			},
		})
		ctx.initializeConfiguration()
		expect(ctx.selectedRegister).toBe(null)
		expect(ctx.configuration).toEqual({ catalog: { schema: null } })
	})
})

describe('Settings autoSelectOpenCatalogiRegister', () => {
	const objectSchemas = [{ id: 1, title: 'Catalog' }, { id: 2, title: 'Listing' }, 7]

	it.each([
		[[{ id: 3, title: 'Other' }, { id: 4, title: 'My Publications', schemas: objectSchemas }], null, { label: 'My Publications', value: '4' }, true],
		[[{ id: 4, title: 'PUBLICATION', schemas: [7, 8] }], null, { label: 'PUBLICATION', value: '4' }, false],
		[[{ id: 4, title: 'publication', schemas: [] }], { label: 'x', value: '1' }, { label: 'publication', value: '4' }, false],
		[[{ id: 3, title: 'First', schemas: objectSchemas }, { id: 4, title: 'Second' }], null, { label: 'First', value: '3' }, true],
		[[{ id: 3, title: 'First', schemas: [null] }], null, { label: 'First', value: '3' }, false],
		[[{ id: 3, title: 'First', schemas: objectSchemas }], { label: 'Kept', value: '1' }, { label: 'Kept', value: '1' }, false],
		[[], null, null, false],
	])('picks from %j (selected %j)', (registers, selected, expected, matched) => {
		const ctx = vm({
			selectedRegister: selected,
			settings: { objectTypes: ['catalog', 'listing', 'theme'], availableRegisters: registers },
		})
		ctx.autoSelectOpenCatalogiRegister()
		expect(ctx.selectedRegister).toEqual(expected)
		if (expected && JSON.stringify(expected) !== JSON.stringify(selected)) {
			expect(ctx.updateSchemaOptions).toHaveBeenCalledWith(expected.value)
		} else {
			expect(ctx.updateSchemaOptions).not.toHaveBeenCalled()
		}
		expect(ctx.configuration).toEqual(
			matched
				? { catalog: { schema: { label: 'Catalog', value: '1' } }, listing: { schema: { label: 'Listing', value: '2' } } }
				: {},
		)
	})

	it('ignores a register without a schemas array when matching', () => {
		const ctx = vm({ settings: { objectTypes: ['catalog'], availableRegisters: [] } })
		ctx.autoSelectMatchingSchemas(null)
		ctx.autoSelectMatchingSchemas({ schemas: 'x' })
		expect(ctx.configuration).toEqual({})
	})
})

describe('Settings remediationHint', () => {
	it.each([
		['http-404', 'Not found (404) — check webserver routing/rewrites for this URL.'],
		['ssrf-blocked', 'Blocked as an unsafe outbound target — check the configured public base URL.'],
		['network-error', 'Could not connect — check that this instance is reachable from the public internet.'],
		['invalid-xml', 'Response was not well-formed XML.'],
		['no-diwoo-elements', 'No DIWOO metadata elements found — the category sitemap may be empty.'],
		['diwoo-xsd-invalid', 'DIWOO metadata failed validation — see the DIWOO validation report for this catalog.'],
		['diwoo-validation-error', 'Could not run DIWOO validation for this catalog/category.'],
		['missing-sitemap-reference', 'The root robots.txt does not reach the app: it names no Woo sitemap.'],
		['sitemapindex-unreachable', 'Skipped — the sitemapindex for this catalog was not reachable.'],
		['sitemapindex-invalid', 'Skipped — the sitemapindex for this catalog was not well-formed.'],
		['no-sitemap-pages', 'Skipped — the sitemapindex has no sitemap pages to sample.'],
		['no-publications-found', 'Skipped — no publication URL was found to sample.'],
		['request-cap-reached', 'Skipped — the per-run outbound request cap was reached.'],
		['not-registered', 'Not yet registered with the Woo-index.'],
		['registration-pending', 'Registration requested but not yet confirmed.'],
		['url-mismatch', "The registered URL does not match this instance's public base URL."],
		['something-else', 'something-else'],
		['', ''],
	])('hints %s', (reason, hint) => {
		expect(vm().remediationHint(reason)).toBe(hint)
	})

	it('translates through the page translator', () => {
		const ctx = vm({ t: (app, text) => `[${app}] ${text}` })
		expect(ctx.remediationHint('invalid-xml')).toBe('[opencatalogi] Response was not well-formed XML.')
	})
})

describe('Settings loadSettings', () => {
	/**
	 * A fetch answering the settings and publishing endpoints.
	 *
	 * @param {object} main - The settings answer
	 * @param {object|Error} publishing - The publishing answer
	 * @return {Function} The fetch
	 */
	function fetchFor(main, publishing) {
		return jest.fn(async (url) => {
			if (url.endsWith('/publishing')) {
				if (publishing instanceof Error) throw publishing
				return { json: async () => publishing }
			}
			return { json: async () => main }
		})
	}

	it('stores the settings, the publishing options, the channel sources and the cache time', async () => {
		const main = { configuration: { channel_sources: '{"a":"b"}', public_api_cache_seconds: 0 } }
		global.fetch = fetchFor(main, { auto_publish_attachments: true, auto_publish_objects: false, use_old_style_publishing_view: true })
		const order = []
		const ctx = vm({ channelSources: { x: 'y' }, publicCacheSeconds: '60', loading: true })
		ctx.initializeConfiguration = () => order.push('init')
		ctx.autoSelectOpenCatalogiRegister = () => order.push('auto')
		await ctx.loadSettings()
		expect(ctx.settings).toBe(main)
		expect(ctx.publishingOptions).toEqual({ autoPublishAttachments: true, autoPublishObjects: false, useOldStylePublishingView: true })
		expect(order).toEqual(['init', 'auto'])
		expect(ctx.channelSources).toEqual({ x: 'y', a: 'b' })
		expect(ctx.publicCacheSeconds).toBe('0')
		expect(ctx.loading).toBe(false)
	})

	it('keeps the publishing options on an error answer and the cache time without one', async () => {
		global.fetch = fetchFor({}, { error: 'no' })
		const ctx = vm({ channelSources: {}, publishingOptions: 'kept', publicCacheSeconds: '60', loading: true })
		ctx.initializeConfiguration = () => {}
		ctx.autoSelectOpenCatalogiRegister = () => {}
		await ctx.loadSettings()
		expect(ctx.publishingOptions).toBe('kept')
		expect(ctx.publicCacheSeconds).toBe('60')
		expect(ctx.loading).toBe(false)
	})

	it('logs a failure and stops loading', async () => {
		global.fetch = fetchFor({}, new Error('down'))
		const ctx = vm({ loading: true })
		await ctx.loadSettings()
		expect(console.error).toHaveBeenCalledWith('Failed to load settings:', expect.any(Error))
		expect(ctx.loading).toBe(false)
	})
})

describe('Settings save and sync actions', () => {
	it('saves the publishing options and shows back what the backend stored', async () => {
		axios.post.mockResolvedValue({ data: { auto_publish_attachments: false, auto_publish_objects: true, use_old_style_publishing_view: false } })
		const ctx = vm({ saving: false, publishingOptions: { autoPublishAttachments: true, autoPublishObjects: false, useOldStylePublishingView: true } })
		await ctx.savePublishingOptions()
		expect(axios.post).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/settings/publishing', {
			auto_publish_attachments: true,
			auto_publish_objects: false,
			use_old_style_publishing_view: true,
		})
		expect(ctx.publishingOptions).toEqual({ autoPublishAttachments: false, autoPublishObjects: true, useOldStylePublishingView: false })
		expect(ctx.saving).toBe(false)
	})

	it.each([
		[{ data: { error: 'bad' } }, 'bad'],
		[new Error('net'), expect.any(Error)],
	])('logs a failed publishing save (%#)', async (outcome, logged) => {
		if (outcome instanceof Error) axios.post.mockRejectedValue(outcome)
		else axios.post.mockResolvedValue(outcome)
		const ctx = vm({ publishingOptions: { kept: true } })
		await ctx.savePublishingOptions()
		expect(console.error).toHaveBeenCalledWith('Failed to save publishing options:', logged)
		expect(ctx.publishingOptions).toEqual({ kept: true })
		expect(ctx.saving).toBe(false)
	})

	it.each([
		['15', 900],
		['abc', 1200],
	])('saves a sync interval of %s minutes as %s seconds', async (minutes, seconds) => {
		global.fetch = jest.fn(async () => ({ json: async () => ({ sync_interval_seconds: 1830 }) }))
		const ctx = vm({ syncOptions: { intervalMinutes: minutes, defaultMinutes: 20 } })
		await ctx.saveSyncOptions()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/sync', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
			body: JSON.stringify({ sync_interval_seconds: seconds }),
		})
		expect(ctx.syncOptions.intervalMinutes).toBe(31)
		expect(mockToasts).toEqual([['success', 'Sync options saved']])
		expect(ctx.savingSyncOptions).toBe(false)
	})

	it('reports a refused and a failed sync interval save', async () => {
		global.fetch = jest.fn(async () => ({ json: async () => ({ error: 'too low' }) }))
		const ctx = vm({ syncOptions: { intervalMinutes: '1', defaultMinutes: 20 } })
		await ctx.saveSyncOptions()
		expect(ctx.syncOptions.intervalMinutes).toBe('1')
		global.fetch = jest.fn(async () => {
			throw new Error('down')
		})
		await ctx.saveSyncOptions()
		expect(mockToasts).toEqual([
			['error', 'Failed to save sync options: too low'],
			['error', 'Failed to save sync options'],
		])
		expect(console.error).toHaveBeenCalledWith('Failed to save sync options:', expect.any(Error))
		expect(ctx.savingSyncOptions).toBe(false)
	})

	it.each([
		[{ synced_directories: 2, failed_directories: 1, total_directories: 4 }, { synced: 2, total: 4, failed: 1 }],
		[{ synced_directories: 2, failed_directories: 1 }, { synced: 2, total: 3, failed: 1 }],
		[{}, { synced: 0, total: 0, failed: 0 }],
	])('reports a directory sync of %j', async (data, counts) => {
		global.fetch = jest.fn(async () => ({ ok: true, json: async () => data }))
		const ctx = vm()
		await ctx.syncDirectoriesNow()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/listings/sync', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
		})
		expect(mockToasts).toEqual([
			['success', `Directories synchronized: {synced} of {total} succeeded, {failed} failed ${JSON.stringify(counts)}`],
		])
		expect(ctx.syncingDirectories).toBe(false)
	})

	it.each([
		[{ ok: false, statusText: 'Forbidden', json: async () => ({ message: 'm', error: 'e' }) }, 'm'],
		[{ ok: false, statusText: 'Forbidden', json: async () => ({ error: 'e' }) }, 'e'],
		[{ ok: false, statusText: 'Forbidden', json: async () => ({}) }, 'Forbidden'],
		[{ ok: true, statusText: 'OK', json: async () => ({ error: 'e2' }) }, 'e2'],
	])('reports a refused directory sync (%#)', async (response, detail) => {
		global.fetch = jest.fn(async () => response)
		await vm().syncDirectoriesNow()
		expect(mockToasts).toEqual([['error', `Failed to synchronize directories: ${detail}`]])
	})

	it('reports a failed directory sync', async () => {
		global.fetch = jest.fn(async () => {
			throw new Error('offline')
		})
		const ctx = vm()
		await ctx.syncDirectoriesNow()
		expect(mockToasts).toEqual([['error', 'Failed to synchronize directories: offline']])
		expect(console.error).toHaveBeenCalledWith('Failed to synchronize directories:', expect.any(Error))
		expect(ctx.syncingDirectories).toBe(false)
	})
})

describe('Settings register change and schema options', () => {
	it.each([
		[[3, 'x', 4], [{ label: 'Schema 3', value: '3' }, { label: 'Schema 4', value: '4' }]],
		[[{ id: 1, title: 'A' }, null, { id: 2 }, 'x'], [{ label: 'A', value: '1' }]],
		[undefined, []],
	])('lists the schema options of %j', (schemas, options) => {
		const ctx = vm({ settings: { availableRegisters: [{ id: 5, schemas }] } })
		Settings.methods.updateSchemaOptions.call(ctx, '5')
		expect(ctx.schemaOptions).toEqual(options)
	})

	it('lists no options for an unknown register', () => {
		const ctx = vm({ settings: { availableRegisters: [] } })
		Settings.methods.updateSchemaOptions.call(ctx, '5')
		expect(ctx.schemaOptions).toEqual([])
	})

	it.each([
		[[{ id: 1, title: 'Catalog' }], { catalog: { schema: { label: 'Catalog', value: '1' } }, theme: { x: 1, schema: null } }],
		[[1, 2], { catalog: { schema: null }, theme: { x: 1, schema: null } }],
		[[], { catalog: { schema: null }, theme: { x: 1, schema: null } }],
	])('resets and re-matches the schemas on a register change (%j)', (schemas, configuration) => {
		const ctx = vm({
			selectedRegister: { label: 'R', value: '5' },
			configuration: { catalog: { schema: { label: 'old', value: '9' } }, theme: { x: 1 } },
			settings: { objectTypes: ['catalog', 'theme'], availableRegisters: [{ id: 5, schemas }] },
		})
		ctx.handleRegisterChange()
		expect(ctx.updateSchemaOptions).toHaveBeenCalledWith('5')
		expect(ctx.configuration).toEqual(configuration)
	})

	it('does nothing on a register change without a selection', () => {
		const ctx = vm({ configuration: { a: 1 } })
		ctx.handleRegisterChange()
		expect(ctx.updateSchemaOptions).not.toHaveBeenCalled()
		expect(ctx.configuration).toEqual({ a: 1 })
	})
})
