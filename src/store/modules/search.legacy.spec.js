/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { createPinia, setActivePinia } from 'pinia'
import { useSearchStore } from './search.js'

// The router is the Nextcloud URL boundary; a fixed prefix keeps the
// expected URLs readable.
jest.mock('@nextcloud/router', () => ({ generateOcsUrl: (path) => `/ocs${path}` }))

const ENDPOINT = '/ocs/apps/opencatalogi/api/federation/publications'

/**
 * Stub fetch with one response for every call.
 *
 * @param {object} body The JSON body
 * @param {boolean} ok Whether the response is ok
 * @return {jest.Mock} The mock
 */
function answerWith(body, ok = true) {
	global.fetch = jest.fn(() => Promise.resolve({
		ok,
		status: ok ? 200 : 502,
		statusText: ok ? 'OK' : 'Bad Gateway',
		json: () => Promise.resolve(body),
	}))
	return global.fetch
}

describe('Search store (search.js)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSearchStore()
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('builds query params, dropping empty values', () => {
		store.searchTerm = 'wet'
		store.filters = { status: 'open', empty: '', none: null }
		store.ordering = { title: 'ASC' }
		expect(store.buildQueryParams({ extra: 1, gone: undefined })).toEqual({
			q: 'wet',
			_page: 1,
			_limit: 20,
			status: 'open',
			_order: { title: 'ASC' },
			_facetable: true,
			_aggregate: true,
			_extend: ['@self.schema', '@self.register'],
			extra: 1,
		})

		store.searchTerm = ''
		store.ordering = {}
		const params = store.buildQueryParams()
		expect(params).not.toHaveProperty('q')
		expect(params).not.toHaveProperty('_order')
	})

	it('builds an API url with arrays, nested objects and scalars', () => {
		expect(store.buildApiUrl('/x')).toBe('/ocs/x')
		const url = store.buildApiUrl('/x', { a: ['1', '2'], o: { t: 'ASC' }, s: 'v' })
		expect(url.startsWith('/ocs/x?')).toBe(true)
		const query = new URLSearchParams(url.split('?')[1])
		expect(query.getAll('a[]')).toEqual(['1', '2'])
		expect(query.get('o[t]')).toBe('ASC')
		expect(query.get('s')).toBe('v')
	})

	it('searches, storing results, pagination, facets and facetable fields', async () => {
		const fetch = answerWith({
			results: [{ id: 'p' }],
			total: 30,
			page: 2,
			pages: 2,
			limit: 15,
			offset: 15,
			facets: { kleur: {} },
			facetable: { '@self': {} },
		})
		await store.searchPublications({ _page: 2, _limit: 15 })

		const [url, init] = fetch.mock.calls[0]
		expect(url.startsWith(`${ENDPOINT}?`)).toBe(true)
		expect(new URLSearchParams(url.split('?')[1]).get('_page')).toBe('2')
		expect(init.method).toBe('GET')
		expect(store.getSearchResults).toEqual([{ id: 'p' }])
		expect(store.getPagination).toEqual({ page: 2, limit: 15, total: 30, pages: 2, offset: 15 })
		expect(store.getFacets).toEqual({ kleur: {} })
		expect(store.getFacetable).toEqual({ '@self': {} })
		expect(store.isSearchLoading).toBe(false)
		expect(store.getError).toBeNull()
	})

	it('falls back to default pagination and keeps facets on a bare answer', async () => {
		store.facets = { kept: {} }
		answerWith({})
		await store.loadInitialResults()
		expect(store.searchResults).toEqual([])
		expect(store.pagination).toEqual({ page: 1, limit: 20, total: 0, pages: 1, offset: 0 })
		expect(store.facets).toEqual({ kept: {} })
	})

	it('records a failed search and empties the results', async () => {
		store.searchResults = [{ id: 'old' }]
		answerWith({}, false)
		await store.searchPublications()
		expect(store.error).toBe('Search failed with status 502: Bad Gateway')
		expect(store.searchResults).toEqual([])
		expect(store.isLoading).toBe(false)

		global.fetch = jest.fn(() => Promise.reject({}))
		await store.searchPublications()
		expect(store.error).toBe('Failed to search publications')
	})

	it('clears the search and reloads the first page', async () => {
		const fetch = answerWith({ results: [] })
		store.searchTerm = 't'
		store.ordering = { a: 'ASC' }
		store.selectedPublications = ['x']
		await store.clearSearch()
		expect(store.getOrdering).toEqual({})
		expect(store.selectedPublications).toEqual([])
		expect(fetch).toHaveBeenCalledTimes(1)
	})

	it('fetches a publication and its related parts', async () => {
		const fetch = answerWith({ id: 'p' })
		await expect(store.getPublication('p')).resolves.toEqual({ id: 'p' })
		await store.getPublicationUses('p')
		await store.getPublicationUsed('p')
		await store.getPublicationAttachments('p')
		expect(fetch.mock.calls.map((c) => c[0])).toEqual([
			`${ENDPOINT}/p`,
			`${ENDPOINT}/p/uses`,
			`${ENDPOINT}/p/used`,
			`${ENDPOINT}/p/attachments`,
		])
	})

	it('refuses a publication without an id and rethrows fetch failures', async () => {
		await expect(store.getPublication('')).rejects.toThrow('Publication ID is required')
		answerWith({}, false)
		await expect(store.getPublicationUses('p')).rejects.toThrow('Failed to fetch publication uses: 502 Bad Gateway')
		await expect(store.getPublication('p')).rejects.toThrow('Failed to fetch publication: 502 Bad Gateway')
	})

	it('manages filters, ordering, view mode and selection', () => {
		store.setFilters({ a: 1, b: 2 })
		store.clearFilter('a')
		expect(store.filters).toEqual({ b: 2 })
		store.clearAllFilters()
		expect(store.filters).toEqual({})

		store.setOrdering('t', 'DESC')
		store.setOrdering('u', 'ASC')
		store.removeOrdering('t')
		expect(store.ordering).toEqual({ u: 'ASC' })

		store.setViewMode('table')
		store.setViewMode('grid')
		expect(store.viewMode).toBe('table')

		store.setFacets(null)
		store.setFacetable(undefined)
		store.setSearchResults(null)
		expect(store.facets).toEqual({})
		expect(store.facetable).toEqual({})
		expect(store.searchResults).toEqual([])

		store.selectedPublications = ['a']
		store.searchResults = [{ id: 'a' }, { id: 'b' }, {}]
		store.selectAllPublications()
		expect(store.selectedPublications).toEqual(['a', 'b'])
		store.togglePublicationSelection('a', true)
		expect(store.selectedPublications).toEqual(['a', 'b'])
	})
})
