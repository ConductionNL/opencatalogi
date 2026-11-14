/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { createPinia, setActivePinia } from 'pinia'
import { useSearchStore } from './search.ts'

const FEDERATION = '/index.php/apps/opencatalogi/api/federation/publications'

/**
 * Stub fetch with one response for every call.
 *
 * @param {object} body The JSON body
 * @param {boolean} ok Whether the response is ok
 * @param {number} status The HTTP status
 * @return {jest.Mock} The mock
 */
function answerWith(body, ok = true, status = 200) {
	const mock = jest.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) }))
	global.fetch = mock
	return mock
}

/**
 * The query of a fetch call.
 *
 * @param {jest.Mock} mock The fetch mock
 * @param {number} call The call index
 * @return {URLSearchParams} The parsed query
 */
function queryOf(mock, call = 0) {
	return new URLSearchParams(String(mock.mock.calls[call][0]).split('?')[1])
}

describe('Federation search store (search.ts)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSearchStore()
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('manages term, filters and ordering', () => {
		store.setSearchTerm('wet')
		store.setFilters({ a: 1 })
		store.setFilters({ b: 'x' })
		expect(store.getSearchTerm).toBe('wet')
		expect(store.getFilters).toEqual({ a: 1, b: 'x' })
		store.clearFilter('a')
		expect(store.filters).toEqual({ b: 'x' })
		store.clearAllFilters()
		expect(store.filters).toEqual({})

		store.setOrdering('title', 'ASC')
		store.setOrdering('date', 'DESC')
		expect(store.getOrdering).toEqual({ title: 'ASC', date: 'DESC' })
		store.removeOrdering('title')
		expect(store.ordering).toEqual({ date: 'DESC' })
		store.clearOrdering()
		expect(store.ordering).toEqual({})
	})

	it('manages view mode and selection', () => {
		store.setViewMode('table')
		expect(store.getViewMode).toBe('table')

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

	it('exposes the facetable fields through its getters', () => {
		expect(store.hasFacets).toBe(false)
		expect(store.hasFacetableFields).toBe(false)
		expect(store.getMetadataFacets).toEqual({})
		expect(store.getObjectFieldFacets).toEqual({})

		const meta = { type: 'string', description: 'd', facet_types: ['terms'] }
		store.facetable = { '@self': { register: meta } }
		expect(store.hasFacets).toBe(true)
		expect(store.hasFacetableFields).toBe(true)

		store.facetable = { object_fields: { kleur: meta } }
		expect(store.hasFacets).toBe(true)
		expect(store.hasFacetableFields).toBe(true)
		expect(store.getAllFacetFields).toEqual({ kleur: meta })
		expect(store.availableObjectFieldFacets).toEqual({ kleur: meta })
		expect(store.availableMetadataFacets).toEqual({})
		expect(store.getFacetable).toEqual({ object_fields: { kleur: meta } })

		expect(store.hasFacetResults).toBe(false)
		store.facets = { kleur: {} }
		expect(store.hasFacetResults).toBe(true)
		expect(store.currentFacets).toEqual({ kleur: {} })
		expect(store.getFacets).toEqual({ kleur: {} })
		expect(store.isLoading).toBe(false)
		expect(store.getError).toBeNull()
		expect(store.isFacetsLoading).toBe(false)
		expect(store.getSearchResults).toEqual([])
		expect(store.getPagination.limit).toBe(20)
	})

	it('discovers facetable fields with the current context', async () => {
		const fetch = answerWith({ facetable: { '@self': { a: {} } } })
		store.searchTerm = 'zoek'
		store.filters = { status: 'open' }
		await store.discoverFacetableFields({ _limit: 5 })

		const query = queryOf(fetch)
		expect(String(fetch.mock.calls[0][0]).startsWith(`${FEDERATION}?`)).toBe(true)
		expect(query.get('_search')).toBe('zoek')
		expect(query.get('_facetable')).toBe('true')
		expect(query.get('_aggregate')).toBe('true')
		expect(query.get('status')).toBe('open')
		expect(query.get('_limit')).toBe('5')
		expect(store.facetable).toEqual({ '@self': { a: {} } })
		expect(store.facetsLoading).toBe(false)
	})

	it('discovers no fields without a term, and an empty set on an empty or failed answer', async () => {
		const fetch = answerWith({})
		await store.discoverFacetableFields()
		expect(queryOf(fetch).has('_search')).toBe(false)
		expect(queryOf(fetch).get('_limit')).toBe('0')
		expect(store.facetable).toEqual({})

		store.facetable = { '@self': {} }
		answerWith({}, false, 500)
		await store.discoverFacetableFields()
		expect(store.facetable).toEqual({})
		expect(store.facetsLoading).toBe(false)
	})

	it('toggles facets and searches for their buckets while any is active', () => {
		const search = jest.spyOn(store, 'searchPublications').mockResolvedValue(undefined)
		store.toggleActiveFacet('@self.register', 'terms', true)
		expect(store.getActiveFacets).toEqual({ '@self.register': { type: 'terms', config: {} } })
		expect(store.hasActiveFacets).toBe(true)
		expect(search).toHaveBeenCalledTimes(1)

		store.toggleActiveFacet('@self.register', 'terms', false)
		expect(store.activeFacets).toEqual({})
		expect(store.hasActiveFacets).toBe(false)
		expect(search).toHaveBeenCalledTimes(1)

		store.activeFacets = { a: { type: 'terms', config: {} } }
		store.clearAllActiveFacets()
		expect(store.activeFacets).toEqual({})
	})

	it('builds the facet query: metadata under @self, object fields by name', () => {
		store.activeFacets = {
			'@self.created': { type: 'date_histogram', config: { interval: 'month' } },
			kleur: { type: 'terms', config: {} },
		}
		expect(store.buildFacetQuery()).toEqual({
			'@self': { created: { type: 'date_histogram', config: { interval: 'month' } } },
			kleur: { type: 'terms', config: {} },
		})
	})

	it('searches with term, pagination, filters, ordering and facets', async () => {
		const fetch = answerWith({
			results: [{ id: 'p' }],
			page: 2,
			pages: 4,
			total: 70,
			limit: 20,
			offset: 20,
			facets: { kleur: {} },
			facetable: { object_fields: {} },
		})
		store.searchTerm = 'wet'
		store.filters = { status: 'open' }
		store.ordering = { title: 'ASC' }
		store.activeFacets = {
			'@self.register': { type: 'terms', config: {} },
			kleur: { type: 'terms', config: { size: 5, nested: { a: 1 } } },
		}
		await store.searchPublications({ _page: 2 })

		const query = queryOf(fetch)
		expect(query.get('_search')).toBe('wet')
		expect(query.get('_page')).toBe('2')
		expect(query.get('_limit')).toBe('20')
		expect(query.get('status')).toBe('open')
		expect(query.getAll('_extend[]')).toEqual(['@self.schema'])
		expect(query.get('_order[title]')).toBe('ASC')
		expect(query.get('_facets[@self][register][type]')).toBe('terms')
		expect(query.get('_facets[kleur][type]')).toBe('terms')
		// The facet's config object is not flattened into the query.
		expect(query.has('_facets[kleur][config]')).toBe(false)

		expect(store.searchResults).toEqual([{ id: 'p' }])
		expect(store.pagination).toEqual({ page: 2, pages: 4, total: 70, limit: 20, offset: 20 })
		expect(store.facets).toEqual({ kleur: {} })
		expect(store.facetable).toEqual({ object_fields: {} })
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('fills pagination fallbacks and skips facet params when none are active', async () => {
		const fetch = answerWith({})
		await store.searchPublications()
		const query = queryOf(fetch)
		expect(query.has('_search')).toBe(false)
		expect(query.get('_page')).toBe('1')
		expect([...query.keys()].some((k) => k.startsWith('_facets'))).toBe(false)
		expect(store.searchResults).toEqual([])
		expect(store.pagination).toEqual({ page: 1, pages: 1, total: 0, limit: 20, offset: 0 })
	})

	it('adds the register extend when a caller drops the schema extend', () => {
		store.searchQuery = jest.fn(() => ({ _page: '1' }))
		expect(store.buildSearchParams({}).getAll('_extend[]')).toEqual(['@self.register'])
	})

	it('records the failure and resets the results when the search fails', async () => {
		store.searchResults = [{ id: 'old' }]
		store.facets = { a: {} }
		answerWith({}, false, 503)
		await store.searchPublications()
		expect(store.error).toBe('HTTP error! status: 503')
		expect(store.searchResults).toEqual([])
		expect(store.facets).toEqual({})
		expect(store.loading).toBe(false)

		global.fetch = jest.fn(() => Promise.reject('boom'))
		await store.searchPublications()
		expect(store.error).toBe('An error occurred while searching')
	})

	it('loads initial results after discovering the facetable fields', async () => {
		const fetch = answerWith({ results: [] })
		await store.loadInitialResults()
		expect(fetch).toHaveBeenCalledTimes(2)
		expect(queryOf(fetch, 0).get('_limit')).toBe('0')
		expect(queryOf(fetch, 1).get('_limit')).toBe('20')
		expect(queryOf(fetch, 1).get('_page')).toBe('1')
	})

	it('fetches one publication and its relations', async () => {
		let fetch = answerWith({ id: 'p' })
		await expect(store.getPublication('p')).resolves.toEqual({ id: 'p' })
		expect(fetch.mock.calls[0][0]).toBe(`${FEDERATION}/p`)
		expect(fetch.mock.calls[0][1]).toEqual({ method: 'GET', headers: { 'Content-Type': 'application/json' } })

		fetch = answerWith({ results: [] })
		await store.getPublicationUses('p', { _limit: 3 })
		expect(fetch.mock.calls[0][0]).toBe(`${FEDERATION}/p/uses?_limit=3`)
		await store.getPublicationUsed('p')
		expect(fetch.mock.calls[1][0]).toBe(`${FEDERATION}/p/used?`)
	})

	it('rethrows failures of the publication and relation fetches', async () => {
		answerWith({}, false, 404)
		await expect(store.getPublication('p')).rejects.toThrow('HTTP error! status: 404')
		await expect(store.getPublicationUses('p')).rejects.toThrow('404')
		await expect(store.getPublicationUsed('p')).rejects.toThrow('404')
		expect(console.error).toHaveBeenCalledTimes(3)
	})

	it('clears the whole search', () => {
		store.searchTerm = 't'
		store.filters = { a: 1 }
		store.ordering = { b: 'ASC' }
		store.activeFacets = { c: { type: 'terms', config: {} } }
		store.error = 'e'
		store.selectedPublications = ['x']
		store.searchResults = [{ id: 'x' }]
		store.clearSearch()
		expect(store.$state).toEqual(expect.objectContaining({
			searchTerm: '', filters: {}, ordering: {}, activeFacets: {}, error: null,
			selectedPublications: [], searchResults: [], facets: {}, facetable: {},
		}))
	})
})
