/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the object store's collection fetch: the URL it requests, the
 * pagination and facets it derives from the answer, the collection, cache and
 * inner-store mirror it fills, and the catalog menu entries a bare catalog
 * reload refreshes. Only `fetch` and the inner lib store are stubbed.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

/**
 * A fetch Response stand-in.
 *
 * @param {*} body - The JSON body
 * @param {boolean} ok - Whether the response is ok
 * @return {{ok: boolean, json: Function}} The response
 */
function answer(body, ok = true) {
	return { ok, json: async () => body }
}

/**
 * A store with loaded settings, two registered types and a recording inner store.
 *
 * @return {{store: object, inner: object}} The store and its inner store
 */
function preparedStore() {
	const store = useObjectStore()
	store.settings = { objectTypes: [], configuration: {} }
	store.objectTypeRegistry = {
		theme: { schema: '7', register: '3' },
		catalog: { schema: { id: 9 }, register: { uuid: 'reg-uuid' } },
	}
	const inner = { collections: {}, pagination: {}, facets: {} }
	vi.spyOn(store, '_ensureInnerType').mockImplementation(() => {})
	vi.spyOn(store, '_inner').mockReturnValue(inner)
	return { store, inner }
}

/**
 * The query of a requested URL as a plain object.
 *
 * @param {string} url - The URL
 * @return {{path: string, query: object}} Its path and query
 */
function parsed(url) {
	const [path, search] = url.split('?')
	return { path, query: Object.fromEntries(new URLSearchParams(search)) }
}

describe('object store fetchCollection', () => {
	let fetchMock

	beforeEach(() => {
		setActivePinia(createPinia())
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('requests the type\'s register and schema with the default query and the database source for a non-indexed type', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValue(answer({ results: [] }))

		await store.fetchCollection('theme', { _search: 'water', extend: 'x' })

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const { path, query } = parsed(fetchMock.mock.calls[0][0])
		expect(path).toBe('/index.php/apps/openregister/api/objects/3/7')
		expect(query).toEqual({
			_limit: '20',
			_page: '1',
			_extend: 'x',
			_search: 'water',
			_source: 'database',
		})
	})

	it('keeps the caller\'s source and takes register and schema ids out of objects', async () => {
		const { store } = preparedStore()
		store.objectTypeRegistry.publication = { schema: { uuid: 's-uuid' }, register: { id: 4 } }
		fetchMock.mockResolvedValue(answer({ results: [] }))

		await store.fetchCollection('publication', { _source: 'index', _limit: 5, _schema: 1, _register: 2 })

		const { path, query } = parsed(fetchMock.mock.calls[0][0])
		expect(path).toBe('/index.php/apps/openregister/api/objects/4/s-uuid')
		expect(query).toEqual({ _limit: '5', _page: '1', _extend: '@self.schema', _source: 'index' })
	})

	it('stores the results, pagination, objects cache and inner mirror, with facets that have buckets', async () => {
		const { store, inner } = preparedStore()
		fetchMock.mockResolvedValue(answer({
			results: [{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }],
			total: 45,
			page: 2,
			limit: 10,
			next: 'n',
			facets: {
				status: { buckets: [{ key: 'open', count: 3 }, { value: 'shut' }] },
				kind: { data: { buckets: [{ key: 'k', count: 1 }] } },
				empty: { other: true },
			},
		}))

		await store.fetchCollection('theme')

		expect(store.collections.theme).toEqual({ results: [{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }] })
		expect(store.pagination.theme).toEqual({ total: 45, page: 2, pages: 5, limit: 10, next: 'n', prev: null })
		expect(store.objects.theme).toEqual({ a: { id: 'a', title: 'A' }, b: { id: 'b', title: 'B' } })
		expect(inner.collections.theme).toEqual([{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }])
		expect(inner.pagination.theme).toEqual({ total: 45, page: 2, pages: 5, limit: 10 })
		const facets = {
			status: { values: [{ value: 'open', count: 3 }, { value: 'shut', count: 0 }] },
			kind: { values: [{ value: 'k', count: 1 }] },
		}
		expect(store.facets.theme).toEqual(facets)
		expect(inner.facets.theme).toEqual(facets)
		expect(store.loading.theme).toBe(false)
		expect(store.success.theme).toBeNull()
	})

	it('derives one page and the default limit when the answer names no next page', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValue(answer({ results: [] }))

		await store.fetchCollection('theme')

		expect(store.pagination.theme).toEqual({ total: 0, page: 1, pages: 1, limit: 20, next: null, prev: null })
	})

	it('appends to the existing results when asked to', async () => {
		const { store } = preparedStore()
		store.collections.theme = { results: [{ id: 'a' }] }
		fetchMock.mockResolvedValue(answer({ results: [{ id: 'b' }] }))

		await store.fetchCollection('theme', { _page: 2 }, true)

		expect(store.collections.theme.results).toEqual([{ id: 'a' }, { id: 'b' }])
	})

	it('keeps the collection when the inner mirror fails', async () => {
		const { store } = preparedStore()
		store._ensureInnerType.mockImplementation(() => {
			throw new Error('no inner type')
		})
		fetchMock.mockResolvedValue(answer({ results: [{ id: 'a' }] }))

		await store.fetchCollection('theme')

		expect(store.collections.theme.results).toEqual([{ id: 'a' }])
		expect(console.warn).toHaveBeenCalledWith('Inner-store mirror skipped for theme:', 'no inner type')
	})

	it('records the failure, clears loading and rethrows when the answer is not ok', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValue(answer({}, false))

		await expect(store.fetchCollection('theme')).rejects.toThrow('Failed to fetch theme collection')

		expect(store.success.theme).toBe(false)
		expect(store.errors.theme).toBe('Failed to fetch theme collection')
		expect(store.loading.theme).toBe(false)
	})

	it('loads the settings first when they are missing', async () => {
		const { store } = preparedStore()
		store.settings = null
		const fetchSettings = vi.spyOn(store, 'fetchSettings').mockImplementation(async () => {
			store.settings = { objectTypes: [], configuration: {} }
		})
		fetchMock.mockResolvedValue(answer({ results: [] }))

		await store.fetchCollection('theme')

		expect(fetchSettings).toHaveBeenCalledTimes(1)
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})

	it('refreshes the catalog menu entries after a bare catalog reload only', async () => {
		const { store } = preparedStore()
		const menu = vi.spyOn(store, 'fetchMenuCatalogs').mockResolvedValue(undefined)
		fetchMock.mockResolvedValue(answer({ results: [] }))

		await store.fetchCollection('catalog', { _search: 'x' })
		await store.fetchCollection('catalog', {}, true)
		expect(menu).not.toHaveBeenCalled()

		await store.fetchCollection('catalog')
		expect(menu).toHaveBeenCalledTimes(1)
		expect(typeof menu.mock.calls[0][0]).toBe('number')
	})
})

describe('object store menu catalogs', () => {
	let fetchMock

	beforeEach(() => {
		setActivePinia(createPinia())
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('walks every page and keeps the catalogs with a slug as menu entries', async () => {
		const { store } = preparedStore()
		fetchMock
			.mockResolvedValueOnce(answer({
				results: [
					{ id: 'c1', slug: 'one', title: 'One', registers: [3, '3', 0], schemas: '[7]' },
					{ id: 'c2', title: 'No slug' },
				],
				pages: 2,
			}))
			.mockResolvedValueOnce(answer({
				results: [{ '@self': { id: 'c3' }, slug: 42, title: { nl: 'x' } }],
				pages: 2,
			}))

		await store.fetchMenuCatalogs()

		expect(fetchMock).toHaveBeenCalledTimes(2)
		const first = parsed(fetchMock.mock.calls[0][0])
		expect(first.path).toBe('/index.php/apps/openregister/api/objects/reg-uuid/9')
		expect(first.query).toMatchObject({ _limit: '100', _page: '1', _source: 'database' })
		expect(parsed(fetchMock.mock.calls[1][0]).query._page).toBe('2')
		expect(store.menuCatalogs).toEqual([
			{ id: 'c1', slug: 'one', title: 'One', registers: [3], schemas: [7] },
			{ id: 'c3', slug: '42', title: '', registers: [], schemas: [] },
		])
	})

	it('stops at an empty page and derives the page count from the total', async () => {
		const { store } = preparedStore()
		fetchMock
			.mockResolvedValueOnce(answer({ results: [{ id: 'c1', slug: 'one' }], total: 250 }))
			.mockResolvedValueOnce(answer({ results: 'none' }))

		await store.fetchMenuCatalogs()

		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(store.menuCatalogs.map((c) => c.slug)).toEqual(['one'])
	})

	it('joins a walk that started after the given sequence instead of starting another', async () => {
		const { store } = preparedStore()
		let release
		fetchMock.mockImplementation(() => new Promise((resolve) => {
			release = () => resolve(answer({ results: [{ id: 'c1', slug: 'one' }], pages: 1 }))
		}))

		const first = store.fetchMenuCatalogs()
		const joined = store.fetchMenuCatalogs(0)
		await Promise.resolve()
		release()
		await Promise.all([first, joined])

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(store.menuCatalogs.map((c) => c.slug)).toEqual(['one'])
	})

	it('discards a walk that a newer walk overtook', async () => {
		const { store } = preparedStore()
		const releases = []
		fetchMock.mockImplementation(() => new Promise((resolve) => {
			releases.push(resolve)
		}))

		const older = store.fetchMenuCatalogs()
		const newer = store.fetchMenuCatalogs()
		await vi.waitFor(() => expect(releases).toHaveLength(2))
		releases[1](answer({ results: [{ id: 'n', slug: 'newer' }], pages: 1 }))
		await newer
		releases[0](answer({ results: [{ id: 'o', slug: 'older' }], pages: 1 }))
		await older

		expect(store.menuCatalogs.map((c) => c.slug)).toEqual(['newer'])
	})

	it('rejects when a page cannot be read', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValue(answer({}, false))

		await expect(store.fetchMenuCatalogs()).rejects.toThrow('Failed to fetch menu catalogs')
		expect(store.menuCatalogs).toEqual([])
	})

	it('looks one catalog up by its exact slug', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValue(answer({
			results: [{ id: 'x', slug: 'water-x' }, { id: 'w', slug: 'water', title: 'Water', schemas: [5] }],
		}))

		const catalog = await store.fetchMenuCatalogBySlug('water')

		expect(parsed(fetchMock.mock.calls[0][0]).query).toMatchObject({ slug: 'water', _limit: '2', _source: 'database' })
		expect(catalog).toEqual({ id: 'w', slug: 'water', title: 'Water', registers: [], schemas: [5] })
		expect(store.menuCatalogs).toEqual([])
	})

	it('answers null for an unknown slug and throws when the lookup fails', async () => {
		const { store } = preparedStore()
		fetchMock.mockResolvedValueOnce(answer({ results: null }))
		expect(await store.fetchMenuCatalogBySlug('nope')).toBeNull()

		fetchMock.mockResolvedValueOnce(answer({}, false))
		await expect(store.fetchMenuCatalogBySlug('nope')).rejects.toThrow('Failed to fetch the catalog')
	})
})
