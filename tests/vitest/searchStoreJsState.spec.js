/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the JS search store's state actions and getters: filters,
 * ordering, view mode, selection and the reset clearSearch performs.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.js'

describe('JS search store state', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'info').mockImplementation(() => {})
		store = useSearchStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('exposes its state through the getters', () => {
		store.setSearchTerm('woo')
		store.setFacets({ type: { buckets: [] } })
		store.setFacetable({ '@self': {} })
		store.setSearchResults([{ id: 'a' }])

		expect(store.getSearchTerm).toBe('woo')
		expect(store.getSearchResults).toEqual([{ id: 'a' }])
		expect(store.isSearchLoading).toBe(false)
		expect(store.getError).toBeNull()
		expect(store.getPagination).toEqual({ page: 1, limit: 20, total: 0, pages: 0, offset: 0 })
		expect(store.getFacets).toEqual({ type: { buckets: [] } })
		expect(store.getFacetable).toEqual({ '@self': {} })
		expect(store.getViewMode).toBe('cards')
		expect(store.getSelectedPublications).toEqual([])
	})

	it('merges filters and clears one or all of them', () => {
		store.setFilters({ status: 'published' })
		store.setFilters({ type: 'besluit' })
		expect(store.getFilters).toEqual({ status: 'published', type: 'besluit' })

		store.clearFilter('status')
		expect(store.getFilters).toEqual({ type: 'besluit' })

		store.clearAllFilters()
		expect(store.getFilters).toEqual({})
	})

	it('keeps ordering per field and removes one or all of it', () => {
		store.setOrdering('title', 'ASC')
		store.setOrdering('published', 'DESC')
		store.setOrdering('title', 'DESC')
		expect(store.getOrdering).toEqual({ title: 'DESC', published: 'DESC' })

		store.removeOrdering('title')
		expect(store.getOrdering).toEqual({ published: 'DESC' })

		store.clearOrdering()
		expect(store.getOrdering).toEqual({})
	})

	it('accepts only the cards and table view modes', () => {
		store.setViewMode('table')
		expect(store.getViewMode).toBe('table')

		store.setViewMode('grid')
		expect(store.getViewMode).toBe('table')
	})

	it('selects a publication once and deselects it', () => {
		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('b', true)
		expect(store.getSelectedPublications).toEqual(['a', 'b'])

		store.togglePublicationSelection('a', false)
		expect(store.getSelectedPublications).toEqual(['b'])
	})

	it('adds every result with an id to the selection without duplicates', () => {
		store.togglePublicationSelection('b', true)
		store.setSearchResults([{ id: 'a' }, { id: 'b' }, { title: 'no id' }])

		store.selectAllPublications()
		expect(store.getSelectedPublications).toEqual(['b', 'a'])

		store.clearAllSelections()
		expect(store.getSelectedPublications).toEqual([])
	})

	it('loads the first page as the initial results', async () => {
		const search = vi.spyOn(store, 'searchPublications').mockResolvedValue(undefined)

		await store.loadInitialResults()

		expect(search).toHaveBeenCalledWith({ _page: 1 })
	})

	it('resets term, filters, ordering, selection and pagination before loading again', async () => {
		const search = vi.spyOn(store, 'searchPublications').mockResolvedValue(undefined)
		store.setSearchTerm('woo')
		store.setFilters({ status: 'published' })
		store.setOrdering('title', 'ASC')
		store.togglePublicationSelection('a', true)
		store.setPagination({ page: 4, limit: 50, total: 300, pages: 6, offset: 150 })

		await store.clearSearch()

		expect(store.getSearchTerm).toBe('')
		expect(store.getFilters).toEqual({})
		expect(store.getOrdering).toEqual({})
		expect(store.getSelectedPublications).toEqual([])
		expect(store.getPagination).toMatchObject({ page: 1, limit: 20, total: 0, pages: 0, offset: 0 })
		expect(search).toHaveBeenCalledWith({ _page: 1 })
	})
})
