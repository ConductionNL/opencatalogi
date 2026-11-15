/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the empty and defaulted publication pages of the catalog
 * store: clearActiveCatalog, the page a failed fetch leaves, and the
 * publicationPagination getter, field values and key order included.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		availableSchemas: [],
		unregisterObjectType: vi.fn(),
		setLoading: vi.fn(),
	},
	navigationStore: {},
	searchStore: {},
	catalogStore: {},
}))

import { useCatalogStore } from '../../src/store/modules/catalog.js'
import { objectStore } from '../../src/store/store.js'

const EMPTY_PAGE = { results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0 }

describe('catalog store publication pages', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		objectStore.unregisterObjectType.mockClear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('clearActiveCatalog unregisters the types and empties the catalog state', () => {
		const store = useCatalogStore()
		store.registeredTypes = new Set(['a', 'b'])
		store.activeCatalog = { id: 1 }
		store.activePublication = { id: 2 }
		store.publications = { results: [{ id: 3 }], total: 1, page: 2, pages: 2, limit: 1, offset: 1 }

		store.clearActiveCatalog()

		expect(objectStore.unregisterObjectType.mock.calls).toEqual([['a'], ['b']])
		expect(store.registeredTypes.size).toBe(0)
		expect(store.activeCatalog).toBeNull()
		expect(store.activePublication).toBeNull()
		expect(store.publications).toEqual(EMPTY_PAGE)
		expect(Object.keys(store.publications)).toEqual(Object.keys(EMPTY_PAGE))
	})

	it('leaves an empty page, in the same key order, when a fetch fails', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		globalThis.fetch = vi.fn(async () => {
			throw new Error('down')
		})
		const store = useCatalogStore()
		await store.fetchPublications({}, 'woo')

		expect(store.publications).toEqual(EMPTY_PAGE)
		expect(Object.keys(store.publications)).toEqual(Object.keys(EMPTY_PAGE))
	})

	it('publicationPagination fills every missing or falsy field, in a fixed key order', () => {
		const store = useCatalogStore()
		store.publications = { results: [], page: 0, pages: '', total: null }

		expect(store.publicationPagination).toEqual({ page: 1, pages: 0, total: 0, limit: 20, offset: 0 })
		expect(Object.keys(store.publicationPagination)).toEqual(['page', 'pages', 'total', 'limit', 'offset'])
	})

	it('publicationPagination passes set fields through', () => {
		const store = useCatalogStore()
		store.publications = { results: [], page: 3, pages: 4, total: 70, limit: 25, offset: 50 }

		expect(store.publicationPagination).toEqual({ page: 3, pages: 4, total: 70, limit: 25, offset: 50 })
	})
})
