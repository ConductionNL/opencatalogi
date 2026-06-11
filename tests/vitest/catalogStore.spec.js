/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the SYNCHRONOUS surface of the catalog Pinia store
 * (src/store/modules/catalog.js): view-mode + pagination setters, the
 * active-catalog/publication getters, and the pagination projection. These
 * are state transitions and derivations the catalog UI depends on; the async
 * OR-backed fetch actions are out of scope here (covered by the Jest suite).
 *
 * The sibling store-registry module (store.js) is mocked so importing the
 * catalog store does not boot the full Pinia tree / NC shell.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// catalog.js imports `objectStore` from ../../store/store.js, which would
// otherwise initialise every store (navigation/search/object) on import.
vi.mock('../../src/store/store.js', () => ({
	objectStore: { availableSchemas: [] },
	navigationStore: {},
	searchStore: {},
	catalogStore: {},
}))

import { useCatalogStore } from '../../src/store/modules/catalog.js'

describe('catalog store — view mode & pagination setters', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('defaults to cards view and page-1 pagination', () => {
		const store = useCatalogStore()
		expect(store.viewMode).toBe('cards')
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
	})

	it('setViewMode updates the view mode', () => {
		const store = useCatalogStore()
		store.setViewMode('table')
		expect(store.viewMode).toBe('table')
	})

	it('setPagination sets page and defaults limit to 20', () => {
		const store = useCatalogStore()
		store.setPagination(3)
		expect(store.pagination).toEqual({ page: 3, limit: 20 })
		store.setPagination(2, 50)
		expect(store.pagination).toEqual({ page: 2, limit: 50 })
	})
})

describe('catalog store — getters', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('availableRegisters / availableSchemas read from the active catalog (empty when none)', () => {
		const store = useCatalogStore()
		expect(store.availableRegisters).toEqual([])
		expect(store.availableSchemas).toEqual([])
		store.activeCatalog = { registers: ['r1', 'r2'], schemas: ['s1'] }
		expect(store.availableRegisters).toEqual(['r1', 'r2'])
		expect(store.availableSchemas).toEqual(['s1'])
	})

	it('hasActiveCatalog / hasActivePublication reflect presence', () => {
		const store = useCatalogStore()
		expect(store.hasActiveCatalog).toBe(false)
		expect(store.hasActivePublication).toBe(false)
		store.activeCatalog = { id: 'c1' }
		store.activePublication = { id: 'p1' }
		expect(store.hasActiveCatalog).toBe(true)
		expect(store.hasActivePublication).toBe(true)
	})

	it('clearActivePublication resets the active publication', () => {
		const store = useCatalogStore()
		store.activePublication = { id: 'p1' }
		store.clearActivePublication()
		expect(store.activePublication).toBeNull()
	})

	it('publicationPagination projects defaults and overrides from the publications collection', () => {
		const store = useCatalogStore()
		expect(store.publicationPagination).toEqual({
			page: 1, pages: 0, total: 0, limit: 20, offset: 0,
		})
		store.publications = { page: 2, pages: 5, total: 90, limit: 20, offset: 20, results: [] }
		expect(store.publicationPagination).toEqual({
			page: 2, pages: 5, total: 90, limit: 20, offset: 20,
		})
	})
})
