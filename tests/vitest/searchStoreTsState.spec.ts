/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the federation search store's state actions and getters: filters,
 * ordering, view mode, selection, the facet getters and the initial load.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

describe('federation search store state', () => {
	let store: ReturnType<typeof useSearchStore>

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'log').mockImplementation(() => {})
		store = useSearchStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('merges filters and clears one or all of them', () => {
		store.setSearchTerm('woo')
		store.setFilters({ status: 'published' })
		store.setFilters({ type: 'besluit' })
		expect(store.getSearchTerm).toBe('woo')
		expect(store.getFilters).toEqual({ status: 'published', type: 'besluit' })

		store.clearFilter('status')
		expect(store.getFilters).toEqual({ type: 'besluit' })

		store.clearAllFilters()
		expect(store.getFilters).toEqual({})
	})

	it('keeps ordering per field and removes one or all of it', () => {
		store.setOrdering('title', 'ASC')
		store.setOrdering('published', 'DESC')
		expect(store.getOrdering).toEqual({ title: 'ASC', published: 'DESC' })

		store.removeOrdering('title')
		expect(store.getOrdering).toEqual({ published: 'DESC' })

		store.clearOrdering()
		expect(store.getOrdering).toEqual({})
	})

	it('sets the view mode and the selection', () => {
		store.setViewMode('table')
		expect(store.getViewMode).toBe('table')

		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('b', true)
		expect(store.getSelectedPublications).toEqual(['a', 'b'])

		store.togglePublicationSelection('a', false)
		expect(store.getSelectedPublications).toEqual(['b'])

		store.clearAllSelections()
		expect(store.getSelectedPublications).toEqual([])
	})

	it('replaces the selection with every current result', () => {
		store.togglePublicationSelection('old', true)
		store.searchResults = [{ id: 'a' }, { id: 'b' }] as never

		store.selectAllPublications()

		expect(store.getSelectedPublications).toEqual(['a', 'b'])
		expect(store.getSearchResults).toEqual([{ id: 'a' }, { id: 'b' }])
	})

	it('reports no facets while nothing is facetable', () => {
		expect(store.hasFacets).toBe(false)
		expect(store.hasFacetableFields).toBe(false)
		expect(store.getMetadataFacets).toEqual({})
		expect(store.getObjectFieldFacets).toEqual({})
		expect(store.getAllFacetFields).toEqual({})
		expect(store.availableMetadataFacets).toEqual({})
		expect(store.availableObjectFieldFacets).toEqual({})
		expect(store.hasFacetResults).toBe(false)
		expect(store.hasActiveFacets).toBe(false)
	})

	it('splits facetable fields into metadata and object fields and merges them', () => {
		const status = { type: 'terms' }
		const theme = { type: 'terms' }
		store.facetable = { '@self': { status }, object_fields: { theme } } as never
		store.facets = { '@self': { status: { buckets: [] } } } as never
		store.activeFacets = { theme: { type: 'terms', config: {} } } as never

		expect(store.hasFacets).toBe(true)
		expect(store.hasFacetableFields).toBe(true)
		expect(store.getMetadataFacets).toEqual({ status })
		expect(store.availableMetadataFacets).toEqual({ status })
		expect(store.getObjectFieldFacets).toEqual({ theme })
		expect(store.availableObjectFieldFacets).toEqual({ theme })
		expect(store.getAllFacetFields).toEqual({ status, theme })
		expect(store.getFacetable).toEqual({ '@self': { status }, object_fields: { theme } })
		expect(store.hasFacetResults).toBe(true)
		expect(store.currentFacets).toEqual({ '@self': { status: { buckets: [] } } })
		expect(store.getFacets).toEqual({ '@self': { status: { buckets: [] } } })
		expect(store.hasActiveFacets).toBe(true)
		expect(store.getActiveFacets).toEqual({ theme: { type: 'terms', config: {} } })
	})

	it('exposes loading and error state', () => {
		store.loading = true
		store.facetsLoading = true
		store.error = 'down' as never

		expect(store.isLoading).toBe(true)
		expect(store.isFacetsLoading).toBe(true)
		expect(store.getError).toBe('down')
		expect(store.getPagination).toBe(store.pagination)
	})

	it('clears every active facet', () => {
		store.activeFacets = { theme: { type: 'terms', config: {} } } as never

		store.clearAllActiveFacets()

		expect(store.getActiveFacets).toEqual({})
	})

	it('discovers facetable fields before loading the first page', async () => {
		const calls: string[] = []
		vi.spyOn(store, 'discoverFacetableFields').mockImplementation(async () => {
			calls.push('discover')
		})
		const search = vi.spyOn(store, 'searchPublications').mockImplementation(async () => {
			calls.push('search')
		})

		await store.loadInitialResults()

		expect(calls).toEqual(['discover', 'search'])
		expect(search).toHaveBeenCalledWith({ _limit: 20, _page: 1 })
	})
})
