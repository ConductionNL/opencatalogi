/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { createPinia, setActivePinia } from 'pinia'
import { objectStore } from '../../store/store.js'
import { useCatalogStore } from './catalog.js'

// The object store is another store (a boundary for this one); stub the
// handful of calls the catalog store makes on it.
jest.mock('../../store/store.js', () => ({
	objectStore: {
		setLoading: jest.fn(),
		setCollection: jest.fn(),
		registerObjectType: jest.fn(() => Promise.resolve()),
		unregisterObjectType: jest.fn(),
		getActiveObject: jest.fn(),
	},
}))

/**
 * A fetch mock answering every call with the given JSON body.
 *
 * @param {object} body - The JSON body
 * @return {jest.Mock} The mock
 */
function answerWith(body) {
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve(body) }))
	return global.fetch
}

describe('Catalog store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useCatalogStore()
		jest.clearAllMocks()
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('sets view mode and pagination', () => {
		store.setViewMode('table')
		store.setPagination(3)
		expect(store.viewMode).toBe('table')
		expect(store.pagination).toEqual({ page: 3, limit: 20 })
		store.setPagination(2, 50)
		expect(store.pagination).toEqual({ page: 2, limit: 50 })
	})

	it('sets and clears the active publication', () => {
		expect(store.hasActivePublication).toBe(false)
		store.setActivePublication({ id: 'p1' })
		expect(store.hasActivePublication).toBe(true)
		expect(store.getActivePublication).toEqual({ id: 'p1' })
		store.clearActivePublication()
		expect(store.activePublication).toBeNull()
	})

	it('refuses to fetch publications without any catalog', async () => {
		const fetch = answerWith({})
		await store.fetchPublications()
		expect(fetch).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalled()
	})

	it('builds the page query from params and pagination', async () => {
		const fetch = answerWith({ results: [] })
		store.setPagination(4, 10)
		await store.fetchPublications({ limit: 5, q: 'zoek' }, 'my-cat')

		const url = fetch.mock.calls[0][0]
		expect(url.startsWith('/index.php/apps/opencatalogi/api/my-cat?')).toBe(true)
		const query = new URLSearchParams(url.split('?')[1])
		expect(query.get('_page')).toBe('4')
		expect(query.get('_limit')).toBe('5')
		expect(query.get('_extend')).toBe('@self.schema,@self.register')
		expect(query.get('q')).toBe('zoek')
		expect(query.has('limit')).toBe(false)
		expect(query.has('page')).toBe(false)
		expect(fetch.mock.calls[0][1]).toEqual({
			method: 'GET',
			headers: { 'Content-Type': 'application/json' },
		})
		expect(store.lastCatalogId).toBe('my-cat')
		expect(objectStore.setLoading).toHaveBeenCalledWith('publication', true)
		expect(objectStore.setLoading).toHaveBeenLastCalledWith('publication', false)
		expect(store.loading).toBe(false)
	})

	it('falls back to page 1 and limit 20 when nothing is set', async () => {
		const fetch = answerWith({})
		store.pagination = {}
		await store.fetchPublications({}, 'c')
		const query = new URLSearchParams(fetch.mock.calls[0][0].split('?')[1])
		expect(query.get('_page')).toBe('1')
		expect(query.get('_limit')).toBe('20')
	})

	it('stores the page, pagination and collection', async () => {
		answerWith({ results: [{ id: 'a' }], total: 41, page: 2, pages: 3, limit: 20, offset: 20 })
		await store.fetchPublications({}, 'c')
		expect(store.publications).toEqual({
			results: [{ id: 'a' }], total: 41, page: 2, pages: 3, limit: 20, offset: 20,
		})
		expect(store.pagination).toEqual({ page: 2, limit: 20 })
		expect(objectStore.setCollection).toHaveBeenCalledWith('publication', [{ id: 'a' }])
		expect(store.publicationPagination).toEqual({ page: 2, pages: 3, total: 41, limit: 20, offset: 20 })
		expect(store.getPublications.total).toBe(41)
	})

	it('defaults every missing page field', async () => {
		answerWith({})
		await store.fetchPublications({}, 'c')
		expect(store.publications).toEqual({ results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 })
	})

	it('leaves an empty page when the request fails', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('down')))
		store.publications = { results: [{ id: 'old' }], total: 1, page: 1, pages: 1, limit: 20, offset: 0 }
		await store.fetchPublications({}, 'c')
		expect(store.publications.results).toEqual([])
		expect(store.loading).toBe(false)
		expect(objectStore.setLoading).toHaveBeenLastCalledWith('publication', false)
	})

	it('resolves the catalog from the active catalog, then the last one', () => {
		store.lastCatalogId = 'last'
		expect(store.resolvePublicationsCatalog(null)).toBe('last')
		store.activeCatalog = { id: 'id-1' }
		expect(store.resolvePublicationsCatalog(null)).toBe('id-1')
		store.activeCatalog = { id: 'id-1', slug: 'slug-1' }
		expect(store.resolvePublicationsCatalog(null)).toBe('slug-1')
		expect(store.resolvePublicationsCatalog('given')).toBe('given')
	})

	it('sets the active catalog and loads its publications', async () => {
		const fetch = answerWith({ results: [] })
		await store.setActiveCatalog({ id: '7', slug: 'cat-7', title: 'Seven', registers: [1], schemas: [2] })
		expect(store.hasActiveCatalog).toBe(true)
		expect(store.availableRegisters).toEqual([1])
		expect(store.availableSchemas).toEqual([2])
		expect(fetch.mock.calls[0][0]).toContain('/api/cat-7?')

		await store.refreshPublications()
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('has no registers or schemas without an active catalog', () => {
		expect(store.availableRegisters).toEqual([])
		expect(store.availableSchemas).toEqual([])
		expect(store.hasActiveCatalog).toBe(false)
		expect(store.isLoading).toBe(false)
	})

	it('registers each publication type once, resolving ids through the page maps', async () => {
		await store.registerPublicationTypes({
			results: [
				{ '@self': { schema: 5, register: 9 } },
				{ '@self': { schema: '5', register: '9' } },
				{ schema: { id: 6, slug: 'embedded' }, register: { id: 10 } },
				{ '@self': { schema: 5 } },
				{ '@self': { schema: 77, register: 9 } },
			],
			'@self': {
				schemas: { 5: { id: 5, slug: 'publicatie' } },
				registers: { 9: { id: 9 } },
			},
		})

		expect(objectStore.registerObjectType.mock.calls).toEqual([
			['publicatie', 5, 9],
			['embedded', 6, 10],
		])
		expect([...store.registeredTypes]).toEqual(['publicatie', 'embedded'])
	})

	it('falls back to the raw references when the page has no maps', async () => {
		await store.registerPublicationTypes({ results: [{ '@self': { schema: 3, register: 4 } }] })
		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
		await store.registerPublicationTypes({})
		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
	})

	it('loads the active publication\'s attachments', async () => {
		objectStore.getActiveObject.mockReturnValue({ id: 'p', '@self': { register: 1, schema: 2 } })
		const fetch = answerWith({ results: [{ id: 'f' }] })
		await store.getPublicationAttachments()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/1/2/p/files')
		expect(objectStore.setCollection).toHaveBeenCalledWith('publicationAttachments', [{ id: 'f' }])

		answerWith({})
		await store.getPublicationAttachments()
		expect(objectStore.setCollection).toHaveBeenLastCalledWith('publicationAttachments', [])
	})

	it('clears the active catalog and unregisters its types', () => {
		store.registeredTypes.add('a')
		store.registeredTypes.add('b')
		store.activeCatalog = { id: 1 }
		store.activePublication = { id: 2 }
		store.publications = { results: [1], total: 1, page: 2, pages: 2, limit: 1, offset: 1 }
		store.clearActiveCatalog()
		expect(objectStore.unregisterObjectType.mock.calls).toEqual([['a'], ['b']])
		expect(store.registeredTypes.size).toBe(0)
		expect(store.activeCatalog).toBeNull()
		expect(store.activePublication).toBeNull()
		expect(store.publications).toEqual({ results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 })
	})
})
