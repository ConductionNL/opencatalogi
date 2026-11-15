/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the federation search Pinia store
 * (src/store/modules/search.ts): search term, filters, ordering and
 * selection state, the facet getters and the facet query it builds, the
 * federation queries it sends (search, facet discovery, a publication and its
 * uses / used-by relations) and how it handles a failed answer.
 *
 * Only the HTTP boundary (global fetch) is replaced.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

const FEDERATION = '/index.php/apps/opencatalogi/api/federation/publications'
const JSON_GET = { method: 'GET', headers: { 'Content-Type': 'application/json' } }

/**
 * Answer every fetch with this status and JSON body.
 *
 * @param {object} body The response body.
 * @param {number} status The HTTP status.
 * @return {import('vitest').Mock} The fetch mock.
 */
function answer(body, status = 200) {
	const fetchMock = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }))
	vi.stubGlobal('fetch', fetchMock)
	return fetchMock
}

/**
 * The path and query of the n-th fetch call.
 *
 * @param {import('vitest').Mock} fetchMock The fetch mock.
 * @param {number} n The call index.
 * @return {{path: string, query: URLSearchParams}} Path and query.
 */
function requested(fetchMock, n = 0) {
	const url = fetchMock.mock.calls[n][0]
	const at = url.indexOf('?')
	return { path: url.slice(0, at), query: new URLSearchParams(url.slice(at + 1)) }
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.spyOn(console, 'log').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('search store — search state', () => {
	it('merges filters, clears one or all of them', () => {
		const store = useSearchStore()
		store.setFilters({ a: '1', b: '2' })
		store.setFilters({ b: '3', c: true })
		expect(store.getFilters).toEqual({ a: '1', b: '3', c: true })

		store.clearFilter('b')
		expect(store.filters).toEqual({ a: '1', c: true })

		store.clearAllFilters()
		expect(store.filters).toEqual({})
	})

	it('sets, removes and clears ordering per field', () => {
		const store = useSearchStore()
		store.setOrdering('title', 'ASC')
		store.setOrdering('published', 'DESC')
		store.setOrdering('title', 'DESC')
		expect(store.getOrdering).toEqual({ title: 'DESC', published: 'DESC' })

		store.removeOrdering('title')
		expect(store.ordering).toEqual({ published: 'DESC' })

		store.clearOrdering()
		expect(store.ordering).toEqual({})
	})

	it('keeps the search term and view mode', () => {
		const store = useSearchStore()
		store.setSearchTerm('parkeren')
		store.setViewMode('table')
		expect(store.getSearchTerm).toBe('parkeren')
		expect(store.getViewMode).toBe('table')
	})

	it('selects a publication once, deselects it, selects all results and clears', () => {
		const store = useSearchStore()
		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('a', true)
		store.togglePublicationSelection('b', true)
		expect(store.getSelectedPublications).toEqual(['a', 'b'])

		store.togglePublicationSelection('a', false)
		expect(store.selectedPublications).toEqual(['b'])

		store.searchResults = [{ id: 'x' }, { id: 'y' }]
		store.selectAllPublications()
		expect(store.selectedPublications).toEqual(['x', 'y'])

		store.clearAllSelections()
		expect(store.selectedPublications).toEqual([])
	})
})

describe('search store — facet getters', () => {
	it('report no facets on a fresh store', () => {
		const store = useSearchStore()
		expect(store.hasFacets).toBe(false)
		expect(store.hasFacetableFields).toBe(false)
		expect(store.hasActiveFacets).toBe(false)
		expect(store.hasFacetResults).toBe(false)
		expect(store.getMetadataFacets).toEqual({})
		expect(store.getObjectFieldFacets).toEqual({})
		expect(store.getAllFacetFields).toEqual({})
	})

	it('split metadata and object field facets and merge them', () => {
		const store = useSearchStore()
		const meta = { created: { type: 'date', description: 'd', facet_types: ['date_histogram'] } }
		const fields = { theme: { type: 'string', description: 't', facet_types: ['terms'] } }
		store.facetable = { '@self': meta, object_fields: fields }
		store.facets = { theme: { buckets: [] } }
		store.activeFacets = { theme: { type: 'terms', config: {} } }

		expect(store.getMetadataFacets).toEqual(meta)
		expect(store.availableMetadataFacets).toEqual(meta)
		expect(store.getObjectFieldFacets).toEqual(fields)
		expect(store.availableObjectFieldFacets).toEqual(fields)
		expect(store.getAllFacetFields).toEqual({ ...meta, ...fields })
		expect(store.hasFacets).toBe(true)
		expect(store.hasFacetableFields).toBe(true)
		expect(store.hasActiveFacets).toBe(true)
		expect(store.hasFacetResults).toBe(true)
		expect(store.currentFacets).toEqual({ theme: { buckets: [] } })
		expect(store.getFacets).toEqual({ theme: { buckets: [] } })
	})

	it('count object fields alone as facets', () => {
		const store = useSearchStore()
		store.facetable = { object_fields: { theme: {} } }
		expect(store.hasFacets).toBe(true)
		expect(store.hasFacetableFields).toBe(true)
	})
})

describe('search store — facet query', () => {
	it('puts @self facets under @self and object fields at the top level', () => {
		const store = useSearchStore()
		store.activeFacets = {
			'@self.created': { type: 'date_histogram', config: { interval: 'month' } },
			theme: { type: 'terms', config: {} },
		}

		expect(store.buildFacetQuery()).toEqual({
			'@self': { created: { type: 'date_histogram', config: { interval: 'month' } } },
			theme: { type: 'terms', config: {} },
		})
	})

	it('toggling a facet on searches for its buckets; toggling the last one off does not', async () => {
		const fetchMock = answer({})
		const store = useSearchStore()

		store.toggleActiveFacet('theme', 'terms', true, { size: 5 })
		expect(store.activeFacets).toEqual({ theme: { type: 'terms', config: { size: 5 } } })
		await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))

		store.toggleActiveFacet('theme', 'terms', false)
		expect(store.activeFacets).toEqual({})
		expect(fetchMock).toHaveBeenCalledTimes(1)

		store.activeFacets = { a: { type: 'terms', config: {} } }
		store.clearAllActiveFacets()
		expect(store.getActiveFacets).toEqual({})
	})
})

describe('search store — searchPublications', () => {
	it('sends term, pagination, filters, ordering and facets, and keeps the answer', async () => {
		const results = [{ id: 'p1' }]
		const fetchMock = answer({
			results,
			page: 2,
			pages: 4,
			total: 61,
			limit: 20,
			offset: 20,
			facets: { theme: { buckets: [{ key: 'a', doc_count: 3 }] } },
			facetable: { object_fields: { theme: {} } },
		})
		const store = useSearchStore()
		store.setSearchTerm('afval')
		store.setFilters({ catalog: 'woo' })
		store.setOrdering('title', 'ASC')
		store.activeFacets = {
			'@self.created': { type: 'date_histogram', config: { interval: 'year' } },
			theme: { type: 'terms', config: {} },
		}

		await store.searchPublications({ _page: '2' })

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][1]).toEqual(JSON_GET)
		const { path, query } = requested(fetchMock)
		expect(path).toBe(FEDERATION)
		expect(query.get('_search')).toBe('afval')
		expect(query.get('_page')).toBe('2')
		expect(query.get('_limit')).toBe('20')
		expect(query.get('_facetable')).toBe('true')
		expect(query.get('_aggregate')).toBe('true')
		expect(query.getAll('_extend[]')).toEqual(['@self.schema'])
		expect(query.get('catalog')).toBe('woo')
		expect(query.get('_order[title]')).toBe('ASC')
		expect(query.get('_facets[@self][created][type]')).toBe('date_histogram')
		expect(query.get('_facets[theme][type]')).toBe('terms')

		expect(store.getSearchResults).toEqual(results)
		expect(store.getPagination).toEqual({ page: 2, pages: 4, total: 61, limit: 20, offset: 20 })
		expect(store.facets).toEqual({ theme: { buckets: [{ key: 'a', doc_count: 3 }] } })
		expect(store.facetable).toEqual({ object_fields: { theme: {} } })
		expect(store.isLoading).toBe(false)
		expect(store.getError).toBeNull()
	})

	it('sends no search term or facet parameters when there are none, and defaults an empty answer', async () => {
		const fetchMock = answer({})
		const store = useSearchStore()

		await store.searchPublications()

		const { query } = requested(fetchMock)
		expect(query.has('_search')).toBe(false)
		expect(query.get('_page')).toBe('1')
		expect([...query.keys()].some((key) => key.startsWith('_facets'))).toBe(false)
		expect(store.searchResults).toEqual([])
		expect(store.pagination).toEqual({ page: 1, pages: 1, total: 0, limit: 20, offset: 0 })
		expect(store.facets).toEqual({})
		expect(store.facetable).toEqual({})
	})

	it('sends an object field facet as its type only, never its nested config', async () => {
		const fetchMock = answer({})
		const store = useSearchStore()
		store.activeFacets = { theme: { type: 'terms', config: {}, size: 10, missing: undefined } }

		await store.searchPublications()

		const { query } = requested(fetchMock)
		expect(query.get('_facets[theme][type]')).toBe('terms')
		expect(query.has('_facets[theme][config]')).toBe(false)
		expect(query.has('_facets[theme][missing]')).toBe(false)
		expect(query.has('_facets[theme][size]')).toBe(false)
	})

	it('records the HTTP error and resets the results when the answer is not ok', async () => {
		answer({ results: [{ id: 'never' }] }, 502)
		const store = useSearchStore()
		store.searchResults = [{ id: 'old' }]
		store.facets = { a: {} }

		await store.searchPublications()

		expect(store.error).toBe('HTTP error! status: 502')
		expect(store.searchResults).toEqual([])
		expect(store.pagination).toEqual({ page: 1, pages: 1, total: 0, limit: 20, offset: 0 })
		expect(store.facets).toEqual({})
		expect(store.facetable).toEqual({})
		expect(store.loading).toBe(false)
	})

	it('records a general error when the failure has no message', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => {
			throw 'offline'  
		}))
		const store = useSearchStore()

		await store.searchPublications()

		expect(store.error).toBe('An error occurred while searching')
	})

	it('loadInitialResults discovers facets first, then loads the first page', async () => {
		const fetchMock = answer({ facetable: { '@self': { created: {} } } })
		const store = useSearchStore()

		await store.loadInitialResults()

		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(requested(fetchMock, 0).query.get('_limit')).toBe('0')
		expect(requested(fetchMock, 1).query.get('_limit')).toBe('20')
		expect(requested(fetchMock, 1).query.get('_page')).toBe('1')
	})
})

describe('search store — discoverFacetableFields', () => {
	it('asks for facetable fields only, in the current search context', async () => {
		const facetable = { object_fields: { theme: {} } }
		const fetchMock = answer({ facetable })
		const store = useSearchStore()
		store.setSearchTerm('subsidie')
		store.setFilters({ catalog: 'woo' })

		await store.discoverFacetableFields({ extra: 'x' })

		const { path, query } = requested(fetchMock)
		expect(path).toBe(FEDERATION)
		expect(Object.fromEntries(query)).toEqual({
			_search: 'subsidie',
			_facetable: 'true',
			_aggregate: 'true',
			_limit: '0',
			catalog: 'woo',
			extra: 'x',
		})
		expect(store.facetable).toEqual(facetable)
		expect(store.isFacetsLoading).toBe(false)
	})

	it('empties the facetable fields when discovery fails', async () => {
		answer({}, 500)
		const store = useSearchStore()
		store.facetable = { object_fields: { old: {} } }

		await store.discoverFacetableFields()

		expect(store.facetable).toEqual({})
		expect(store.facetsLoading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Failed to discover facetable fields:', expect.any(Error))
	})
})

describe('search store — single publication and relations', () => {
	it('getPublication returns the federation answer', async () => {
		const fetchMock = answer({ id: 'p1', title: 'Besluit' })
		const store = useSearchStore()

		await expect(store.getPublication('p1')).resolves.toEqual({ id: 'p1', title: 'Besluit' })
		expect(fetchMock).toHaveBeenCalledWith(`${FEDERATION}/p1`, JSON_GET)
	})

	it('getPublication rethrows a failed answer', async () => {
		answer({}, 404)
		const store = useSearchStore()

		await expect(store.getPublication('p1')).rejects.toThrow('HTTP error! status: 404')
	})

	it('getPublicationUses and getPublicationUsed query the relation endpoints', async () => {
		const fetchMock = answer({ results: [] })
		const store = useSearchStore()

		await expect(store.getPublicationUses('p1', { _limit: '5' })).resolves.toEqual({ results: [] })
		await expect(store.getPublicationUsed('p1')).resolves.toEqual({ results: [] })

		expect(fetchMock.mock.calls[0]).toEqual([`${FEDERATION}/p1/uses?_limit=5`, JSON_GET])
		expect(fetchMock.mock.calls[1]).toEqual([`${FEDERATION}/p1/used?`, JSON_GET])
	})

	it('getPublicationUses and getPublicationUsed rethrow a failed answer', async () => {
		answer({}, 500)
		const store = useSearchStore()

		await expect(store.getPublicationUses('p1')).rejects.toThrow('HTTP error! status: 500')
		await expect(store.getPublicationUsed('p1')).rejects.toThrow('HTTP error! status: 500')
	})
})

describe('search store — clearSearch', () => {
	it('resets every part of the search', () => {
		const store = useSearchStore()
		store.setSearchTerm('x')
		store.setFilters({ a: 1 })
		store.setOrdering('title', 'ASC')
		store.searchResults = [{ id: 'p' }]
		store.pagination = { page: 3, pages: 3, total: 50, limit: 20, offset: 40 }
		store.facets = { a: {} }
		store.facetable = { object_fields: { a: {} } }
		store.activeFacets = { a: { type: 'terms', config: {} } }
		store.error = 'old'
		store.selectedPublications = ['p']

		store.clearSearch()

		expect(store.$state).toMatchObject({
			searchTerm: '',
			filters: {},
			ordering: {},
			searchResults: [],
			pagination: { page: 1, pages: 1, total: 0, limit: 20, offset: 0 },
			facets: {},
			facetable: {},
			activeFacets: {},
			error: null,
			selectedPublications: [],
		})
	})
})
