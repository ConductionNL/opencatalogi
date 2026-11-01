/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterization tests for the catalog store's fetchPublications: the
 * request it sends, the state it keeps, how it registers each publication's
 * object type and what it does when the request fails.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objectStoreMock = vi.hoisted(() => ({
	availableSchemas: [],
	setLoading: vi.fn(),
	setCollection: vi.fn(),
	registerObjectType: vi.fn(async () => {}),
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: objectStoreMock,
	navigationStore: {},
	searchStore: {},
	catalogStore: {},
}))

import { useCatalogStore } from '../../src/store/modules/catalog.js'

/**
 * Make fetch answer with the given body.
 *
 * @param {object} body - The JSON body
 * @return {Function} The fetch mock
 */
function answer(body) {
	globalThis.fetch = vi.fn(async () => ({ json: async () => body }))
	return globalThis.fetch
}

describe('catalog store fetchPublications', () => {
	let store
	let consoleError

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useCatalogStore()
		vi.clearAllMocks()
		consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		consoleError.mockRestore()
	})

	it('does nothing without a catalog', async () => {
		const fetch = answer({})

		await store.fetchPublications()

		expect(fetch).not.toHaveBeenCalled()
		expect(consoleError).toHaveBeenCalledWith('[CatalogStore#fetchPublications] No catalog ID provided and no active catalog exists')
	})

	it('requests the page and keeps the state and pagination', async () => {
		const fetch = answer({ results: [{ id: 'p1' }], total: 41, page: 3, pages: 5, limit: 10, offset: 20 })

		await store.fetchPublications({ page: 3, limit: 10, q: 'x', _order: 'title' }, 'my-catalog')

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/opencatalogi/api/my-catalog?_page=3&_limit=10&_extend=%40self.schema%2C%40self.register&q=x&_order=title')
		expect(fetch.mock.calls[0][1].method).toBe('GET')
		expect(store.lastCatalogId).toBe('my-catalog')
		expect(store.publications).toEqual({ results: [{ id: 'p1' }], total: 41, page: 3, pages: 5, limit: 10, offset: 20 })
		expect(store.pagination).toEqual({ page: 3, limit: 10 })
		expect(store.loading).toBe(false)
		expect(objectStoreMock.setLoading.mock.calls).toEqual([['publication', true], ['publication', false]])
		expect(objectStoreMock.setCollection).toHaveBeenCalledWith('publication', [{ id: 'p1' }])
	})

	it('falls back to the active catalog, then the last one, and to default paging', async () => {
		const fetch = answer({})
		store.activeCatalog = { id: 'c-id', slug: 'c-slug' }

		await store.fetchPublications()
		store.activeCatalog = null
		await store.fetchPublications()

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/opencatalogi/api/c-slug?_page=1&_limit=20&_extend=%40self.schema%2C%40self.register')
		expect(fetch.mock.calls[1][0]).toContain('/api/c-slug?')
		expect(store.publications).toEqual({ results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 })
		expect(objectStoreMock.setCollection).toHaveBeenLastCalledWith('publication', undefined)
	})

	it('registers each object type once, resolving ids through the page maps', async () => {
		answer({
			results: [
				{ '@self': { schema: 5, register: '2' } },
				{ '@self': { schema: '5', register: 2 } },
				{ schema: { id: 9, slug: 'embedded' }, register: { id: 4 } },
				{ '@self': { schema: 6, register: 2 } },
				{ '@self': { schema: 7 } },
			],
			'@self': {
				schemas: { 5: { id: 5, slug: 'publication' }, 6: { title: 'no slug' } },
				registers: { 2: { id: 2 } },
			},
		})

		await store.fetchPublications({}, 'c')

		expect(objectStoreMock.registerObjectType.mock.calls).toEqual([
			['publication', 5, 2],
			['embedded', 9, 4],
		])
		expect([...store.registeredTypes]).toEqual(['publication', 'embedded'])
	})

	it('empties the publications when the request fails', async () => {
		globalThis.fetch = vi.fn(async () => {
			throw new Error('offline')
		})
		store.publications = { results: [{ id: 'old' }] }

		await store.fetchPublications({}, 'c')

		expect(store.publications).toEqual({ results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 })
		expect(store.loading).toBe(false)
		expect(consoleError).toHaveBeenCalledWith('Error fetching publications:', expect.any(Error))
	})
})
