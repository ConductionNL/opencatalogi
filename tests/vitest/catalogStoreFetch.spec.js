/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the ASYNC surface of the catalog Pinia store
 * (src/store/modules/catalog.js): resolving which catalogue to fetch, the
 * query it sends, the collection and pagination it keeps, registering each
 * publication's object type once, the reset on a failed fetch, the
 * attachments fetch and clearing the active catalogue.
 *
 * Only the HTTP boundary (global fetch) and the sibling object store are
 * replaced; the catalog store itself runs for real on a Pinia instance.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = vi.hoisted(() => ({
	setLoading: vi.fn(),
	registerObjectType: vi.fn(async () => {}),
	unregisterObjectType: vi.fn(),
	setCollection: vi.fn(),
	getActiveObject: vi.fn(),
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore,
	navigationStore: {},
	searchStore: {},
	catalogStore: {},
}))

import { useCatalogStore } from '../../src/store/modules/catalog.js'

const EMPTY = { results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 }

/**
 * Answer every fetch with this JSON body.
 *
 * @param {object} body The response body.
 * @return {import('vitest').Mock} The fetch mock.
 */
function answer(body) {
	const fetchMock = vi.fn(async () => ({ json: async () => body }))
	vi.stubGlobal('fetch', fetchMock)
	return fetchMock
}

/**
 * The query parameters of the n-th fetch call.
 *
 * @param {import('vitest').Mock} fetchMock The fetch mock.
 * @param {number} n The call index.
 * @return {{path: string, query: URLSearchParams}} Path and query.
 */
function requested(fetchMock, n = 0) {
	const [path, query] = fetchMock.mock.calls[n][0].split('?')
	return { path, query: new URLSearchParams(query) }
}

describe('catalog store — fetchPublications', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(console, 'info').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('does nothing without any catalogue to fetch', async () => {
		const fetchMock = answer({})
		const store = useCatalogStore()

		await store.fetchPublications()

		expect(fetchMock).not.toHaveBeenCalled()
		expect(objectStore.setLoading).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith(
			'[CatalogStore#fetchPublications] No catalog ID provided and no active catalog exists',
		)
	})

	it('fetches the named catalogue with pagination, extend and extra filters, and keeps the answer', async () => {
		const results = [{ id: 'p1' }, { id: 'p2' }]
		const fetchMock = answer({ results, total: 42, page: 2, pages: 3, limit: 10, offset: 10 })
		const store = useCatalogStore()

		await store.fetchPublications({ page: 2, limit: 10, _search: 'woo' }, 'woo-catalog')

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const { path, query } = requested(fetchMock)
		expect(path).toBe('/index.php/apps/opencatalogi/api/woo-catalog')
		expect(Object.fromEntries(query)).toEqual({
			_page: '2',
			_limit: '10',
			_extend: '@self.schema,@self.register',
			_search: 'woo',
		})
		expect(fetchMock.mock.calls[0][1]).toEqual({ method: 'GET', headers: { 'Content-Type': 'application/json' } })

		expect(store.publications).toEqual({ results, total: 42, page: 2, pages: 3, limit: 10, offset: 10 })
		expect(store.pagination).toEqual({ page: 2, limit: 10 })
		expect(store.lastCatalogId).toBe('woo-catalog')
		expect(store.loading).toBe(false)
		expect(objectStore.setCollection).toHaveBeenCalledWith('publication', results)
		expect(objectStore.setLoading.mock.calls).toEqual([['publication', true], ['publication', false]])
	})

	it('falls back to the active catalogue slug, then its id, then the last catalogue fetched', async () => {
		const fetchMock = answer({})
		const store = useCatalogStore()

		store.activeCatalog = { slug: 'by-slug', id: 'id-1' }
		await store.fetchPublications()
		store.activeCatalog = { id: 'id-2' }
		await store.fetchPublications()
		store.activeCatalog = null
		await store.fetchPublications()

		expect(requested(fetchMock, 0).path).toBe('/index.php/apps/opencatalogi/api/by-slug')
		expect(requested(fetchMock, 1).path).toBe('/index.php/apps/opencatalogi/api/id-2')
		expect(requested(fetchMock, 2).path).toBe('/index.php/apps/opencatalogi/api/id-2')
	})

	it('uses the stored pagination when the call names none and defaults an empty answer', async () => {
		const fetchMock = answer({})
		const store = useCatalogStore()
		store.setPagination(4, 50)

		await store.fetchPublications({}, 'c')

		expect(requested(fetchMock).query.get('_page')).toBe('4')
		expect(requested(fetchMock).query.get('_limit')).toBe('50')
		expect(store.publications).toEqual(EMPTY)
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
		expect(objectStore.setCollection).toHaveBeenCalledWith('publication', undefined)
	})

	it('registers each publication type once, resolving ids through the @self maps', async () => {
		answer({
			results: [
				{ id: 'a', '@self': { schema: 5, register: '9' } },
				{ id: 'b', '@self': { schema: '5', register: 9 } },
				{ id: 'c', schema: { id: 6, slug: 'besluit' }, register: { id: 8 } },
				{ id: 'd', '@self': { schema: 7 } },
				{ id: 'e', '@self': { schema: 99, register: 9 } },
			],
			'@self': {
				schemas: { 5: { id: 5, slug: 'publicatie' } },
				registers: { 9: { id: 9 } },
			},
		})
		const store = useCatalogStore()

		await store.fetchPublications({}, 'c')

		expect(objectStore.registerObjectType.mock.calls).toEqual([
			['publicatie', 5, 9],
			['besluit', 6, 8],
		])
		expect([...store.registeredTypes]).toEqual(['publicatie', 'besluit'])
	})

	it('does not register a type twice across fetches', async () => {
		answer({ results: [{ id: 'a', schema: { id: 1, slug: 'x' }, register: { id: 2 } }] })
		const store = useCatalogStore()

		await store.fetchPublications({}, 'c')
		await store.fetchPublications({}, 'c')

		expect(objectStore.registerObjectType).toHaveBeenCalledTimes(1)
	})

	it('resets the collection and stops loading when the fetch fails', async () => {
		const failure = new Error('offline')
		vi.stubGlobal('fetch', vi.fn(async () => {
			throw failure
		}))
		const store = useCatalogStore()
		store.publications = { results: [{ id: 'old' }], total: 1, page: 2, pages: 2, limit: 1, offset: 1 }

		await store.fetchPublications({}, 'c')

		expect(store.publications).toEqual(EMPTY)
		expect(store.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error fetching publications:', failure)
		expect(objectStore.setCollection).not.toHaveBeenCalled()
		expect(objectStore.setLoading).toHaveBeenLastCalledWith('publication', false)
	})

	it('setActiveCatalog and refreshPublications fetch the active catalogue', async () => {
		const fetchMock = answer({})
		const store = useCatalogStore()

		await store.setActiveCatalog({ id: 'cat-id', slug: 'cat-slug', title: 'Catalogus' })
		await store.refreshPublications()

		expect(store.activeCatalog.slug).toBe('cat-slug')
		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(requested(fetchMock, 1).path).toBe('/index.php/apps/opencatalogi/api/cat-slug')
	})
})

describe('catalog store — attachments and clearing', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('getPublicationAttachments fetches the active publication files from OpenRegister', async () => {
		objectStore.getActiveObject.mockReturnValue({ id: 'pub-1', '@self': { register: 3, schema: 4 } })
		const files = [{ id: 11 }]
		const fetchMock = answer({ results: files })
		const store = useCatalogStore()

		await store.getPublicationAttachments()

		expect(objectStore.getActiveObject).toHaveBeenCalledWith('publication')
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/3/4/pub-1/files')
		expect(objectStore.setCollection).toHaveBeenCalledWith('publicationAttachments', files)
	})

	it('getPublicationAttachments stores an empty list when the answer has no results', async () => {
		objectStore.getActiveObject.mockReturnValue({ id: 'p', '@self': { register: 1, schema: 2 } })
		answer({})
		const store = useCatalogStore()

		await store.getPublicationAttachments()

		expect(objectStore.setCollection).toHaveBeenCalledWith('publicationAttachments', [])
	})

	it('clearActiveCatalog unregisters every type and resets the catalogue state', () => {
		const store = useCatalogStore()
		store.registeredTypes.add('a')
		store.registeredTypes.add('b')
		store.activeCatalog = { id: 'c' }
		store.activePublication = { id: 'p' }
		store.publications = { results: [{ id: 'p' }], total: 1, page: 1, pages: 1, limit: 20, offset: 0 }

		store.clearActiveCatalog()

		expect(objectStore.unregisterObjectType.mock.calls).toEqual([['a'], ['b']])
		expect(store.registeredTypes.size).toBe(0)
		expect(store.activeCatalog).toBeNull()
		expect(store.activePublication).toBeNull()
		expect(store.publications).toEqual(EMPTY)
	})

	it('clearActivePublication drops only the active publication', () => {
		const store = useCatalogStore()
		store.activeCatalog = { id: 'c' }
		store.setActivePublication({ id: 'p' })
		expect(store.hasActivePublication).toBe(true)

		store.clearActivePublication()

		expect(store.activePublication).toBeNull()
		expect(store.hasActivePublication).toBe(false)
		expect(store.activeCatalog).toEqual({ id: 'c' })
	})
})
