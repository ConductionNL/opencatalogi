/**
 * Pinia store for search functionality
 *
 * This store handles search operations for publications across local and federated sources.
 * It provides read-only access to publication data through federation endpoints.
 *
 * @module Store
 * @package
 * @author   Ruben van der Linde
 * @copyright 2024
 * @license  EUPL-1.2
 * @version  1.0.0
 * @see     {@link https://github.com/opencatalogi/opencatalogi}
 */

import { generateOcsUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

const JSON_GET = {
	method: 'GET',
	headers: {
		'Content-Type': 'application/json',
		Accept: 'application/json',
	},
}

/**
 * GET a JSON body, throwing when the response is not ok.
 *
 * @param {string} url The URL to fetch.
 * @param {string} label What is fetched, for the error message.
 * @return {Promise<object>} The parsed body.
 * @spec openspec/specs/search/spec.md
 */
async function getJson(url, label) {
	const response = await fetch(url, JSON_GET)
	if (!response.ok) {
		throw new Error(
			`Failed to fetch ${label}: ${response.status} ${response.statusText}`,
		)
	}
	return await response.json()
}

/**
 * Fetch a federation publication, or one of its sub-resources.
 *
 * @param {string} id The publication ID.
 * @param {string} [part] The sub-resource (`uses`, `used`, `attachments`), if any.
 * @return {Promise<object>} The parsed body.
 * @spec openspec/specs/search/spec.md
 */
async function getFederationPublication(id, part = '') {
	if (!id) {
		throw new Error('Publication ID is required')
	}
	const label = part ? `publication ${part}` : 'publication'
	try {
		return await getJson(federationPublicationUrl(id, part), label)
	} catch (error) {
		console.error(`Error fetching ${label}:`, error)
		throw error
	}
}

/**
 * The OCS url of a federation publication, or of one of its sub-resources.
 *
 * @param {string} id The publication ID.
 * @param {string} part The sub-resource, or '' for the publication itself.
 * @return {string} The url.
 */
function federationPublicationUrl(id, part) {
	const suffix = part ? `/${part}` : ''
	return generateOcsUrl(
		`/apps/opencatalogi/api/federation/publications/${id}${suffix}`,
	)
}

const PAGINATION_DEFAULTS = { page: 1, limit: 20, total: 0, pages: 1, offset: 0 }

/**
 * Run one search: take the page overrides, fetch the page and store the answer.
 *
 * @param {object} store The search store.
 * @param {object} additionalParams The extra search parameters.
 * @return {Promise<void>}
 */
async function runSearch(store, additionalParams) {
	applyPageParams(store.pagination, additionalParams)
	const params = store.buildQueryParams(additionalParams)
	const url = store.buildApiUrl(store.searchEndpoint, params)
	const data = await fetchSearchPage(url)
	applySearchAnswer(store, data)
}

/**
 * Take a search's page and limit overrides into the pagination.
 *
 * @param {object} pagination The store's pagination.
 * @param {object} params The search parameters.
 * @return {void}
 * @spec openspec/specs/search/spec.md
 */
function applyPageParams(pagination, params) {
	if (params._page) {
		pagination.page = params._page
	}
	if (params._limit) {
		pagination.limit = params._limit
	}
}

/**
 * GET a search page, throwing when the response is not ok. Logs the URL.
 *
 * @param {string} url The search URL.
 * @return {Promise<object>} The parsed body.
 * @spec openspec/specs/search/spec.md
 */
async function fetchSearchPage(url) {
	console.info('Searching publications with URL:', url)
	const response = await fetch(url, JSON_GET)
	if (!response.ok) {
		throw new Error(
			`Search failed with status ${response.status}: ${response.statusText}`,
		)
	}
	return await response.json()
}

/**
 * The pagination of a search answer, each missing or empty field at its default.
 *
 * @param {object} data The search answer.
 * @return {object} The pagination.
 * @spec openspec/specs/search/spec.md
 */
function paginationOf(data) {
	return Object.fromEntries(
		Object.entries(PAGINATION_DEFAULTS).map(([key, fallback]) => [
			key,
			data[key] || fallback,
		]),
	)
}

/**
 * Put a search answer in the store: results, pagination, and the facets and
 * facetable metadata when the answer has them. Logs a summary.
 *
 * @param {object} store The search store.
 * @param {object} data The search answer.
 * @return {void}
 * @spec openspec/specs/search/spec.md
 */
function applySearchAnswer(store, data) {
	store.setSearchResults(data.results || [])
	store.setPagination(paginationOf(data))
	if (data.facets) {
		store.setFacets(data.facets)
	}
	if (data.facetable) {
		store.setFacetable(data.facetable)
	}
	console.info('Search completed:', searchSummary(data))
}

/**
 * What the console reports about a finished search.
 *
 * @param {object} data The search answer.
 * @return {object} The total, the number of results and the page.
 * @spec openspec/specs/search/spec.md
 */
function searchSummary(data) {
	return { total: data.total, results: data.results?.length || 0, page: data.page }
}

/**
 * Search store for handling publication search operations
 *
 * Provides functionality for:
 * - Searching publications across local and federated sources
 * - Managing search filters and ordering
 * - Handling pagination of search results
 * - Managing facets and facetable metadata
 * - Read-only operations only (no creation/modification)
 */
export const useSearchStore = defineStore('search', {
	state: () => ({
		// Search results and data
		searchResults: [],
		searchTerm: '',
		isLoading: false,
		error: null,

		// Pagination
		pagination: {
			page: 1,
			limit: 20,
			total: 0,
			pages: 0,
			offset: 0,
		},

		// Filters and facets
		filters: {},
		facets: {},
		facetable: {},

		// Ordering
		ordering: {},

		// View settings
		viewMode: 'cards', // 'cards' or 'table'
		selectedPublications: [],

		// Search configuration
		searchEndpoint: '/apps/opencatalogi/api/federation/publications',
		aggregateByDefault: true,
	}),

	getters: {
		/**
		 * Get current search results
		 *
		 * @param {object} state - The store state
		 * @return {Array} Array of search results
		 */
		getSearchResults: (state) => state.searchResults,

		/**
		 * Get current search term
		 *
		 * @param {object} state - The store state
		 * @return {string} Current search term
		 */
		getSearchTerm: (state) => state.searchTerm,

		/**
		 * Get loading state
		 *
		 * @param {object} state - The store state
		 * @return {boolean} Whether search is currently loading
		 */
		isSearchLoading: (state) => state.isLoading,

		/**
		 * Get error state
		 *
		 * @param {object} state - The store state
		 * @return {string|null} Current error message or null
		 */
		getError: (state) => state.error,

		/**
		 * Get pagination information
		 *
		 * @param {object} state - The store state
		 * @return {object} Pagination object with page, limit, total, etc.
		 */
		getPagination: (state) => state.pagination,

		/**
		 * Get current filters
		 *
		 * @param {object} state - The store state
		 * @return {object} Current filter settings
		 */
		getFilters: (state) => state.filters,

		/**
		 * Get current facets
		 *
		 * @param {object} state - The store state
		 * @return {object} Current facet data
		 */
		getFacets: (state) => state.facets,

		/**
		 * Get facetable metadata
		 *
		 * @param {object} state - The store state
		 * @return {object} Facetable metadata for dynamic filters
		 */
		getFacetable: (state) => state.facetable,

		/**
		 * Get current ordering
		 *
		 * @param {object} state - The store state
		 * @return {object} Current ordering settings
		 */
		getOrdering: (state) => state.ordering,

		/**
		 * Get current view mode
		 *
		 * @param {object} state - The store state
		 * @return {string} Current view mode ('cards' or 'table')
		 */
		getViewMode: (state) => state.viewMode,

		/**
		 * Get selected publications
		 *
		 * @param {object} state - The store state
		 * @return {Array} Array of selected publication IDs
		 */
		getSelectedPublications: (state) => state.selectedPublications,
	},

	actions: {
		/**
		 * Set search term
		 *
		 * @param {string} term - Search term to set
		 */
		setSearchTerm(term) {
			this.searchTerm = term
		},

		/**
		 * Set loading state
		 *
		 * @param {boolean} loading - Loading state
		 */
		setLoading(loading) {
			this.isLoading = loading
		},

		/**
		 * Set error state
		 *
		 * @param {string|null} error - Error message or null
		 */
		setError(error) {
			this.error = error
		},

		/**
		 * Set search results
		 *
		 * @param {Array} results - Search results array
		 */
		setSearchResults(results) {
			this.searchResults = results || []
		},

		/**
		 * Set pagination data
		 *
		 * @param {object} paginationData - Pagination information
		 */
		/**
		 * @param paginationData
		 * @spec openspec/specs/search/spec.md
		 */
		setPagination(paginationData) {
			this.pagination = {
				...this.pagination,
				...paginationData,
			}
		},

		/**
		 * Set filters
		 *
		 * @param {object} filters - Filter object to merge with existing filters
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},

		/**
		 * Clear a specific filter
		 *
		 * @param {string} key - Filter key to clear
		 */
		/**
		 * @param key
		 * @spec openspec/specs/search/spec.md
		 */
		clearFilter(key) {
			const newFilters = { ...this.filters }
			delete newFilters[key]
			this.filters = newFilters
		},

		/**
		 * Clear all filters
		 */
		/** @spec openspec/specs/search/spec.md */
		clearAllFilters() {
			this.filters = {}
		},

		/**
		 * Set facets data
		 *
		 * @param {object} facets - Facets data
		 */
		setFacets(facets) {
			this.facets = facets || {}
		},

		/**
		 * Set facetable metadata
		 *
		 * @param {object} facetable - Facetable metadata
		 */
		setFacetable(facetable) {
			this.facetable = facetable || {}
		},

		/**
		 * Set ordering for a field
		 *
		 * @param {string} field - Field to order by
		 * @param {string} direction - Direction ('ASC' or 'DESC')
		 */
		setOrdering(field, direction) {
			this.ordering = { ...this.ordering, [field]: direction }
		},

		/**
		 * Remove ordering for a field
		 *
		 * @param {string} field - Field to remove ordering for
		 */
		/**
		 * @param field
		 * @spec openspec/specs/search/spec.md
		 */
		removeOrdering(field) {
			const newOrdering = { ...this.ordering }
			delete newOrdering[field]
			this.ordering = newOrdering
		},

		/**
		 * Clear all ordering
		 */
		/** @spec openspec/specs/search/spec.md */
		clearOrdering() {
			this.ordering = {}
		},

		/**
		 * Set view mode
		 *
		 * @param {string} mode - View mode ('cards' or 'table')
		 */
		/**
		 * @param mode
		 * @spec openspec/specs/search/spec.md
		 */
		setViewMode(mode) {
			if (['cards', 'table'].includes(mode)) {
				this.viewMode = mode
			}
		},

		/**
		 * Toggle publication selection
		 *
		 * @param {string} id - Publication ID
		 * @param {boolean} selected - Whether to select or deselect
		 */
		/**
		 * @param id
		 * @param selected
		 * @spec openspec/specs/search/spec.md
		 */
		togglePublicationSelection(id, selected) {
			if (selected && !this.selectedPublications.includes(id)) {
				this.selectedPublications.push(id)
			} else if (!selected) {
				this.selectedPublications = this.selectedPublications.filter(
					(pubId) => pubId !== id,
				)
			}
		},

		/**
		 * Select all current publications
		 */
		/** @spec openspec/specs/search/spec.md */
		selectAllPublications() {
			const currentIds = this.searchResults
				.map((pub) => pub.id)
				.filter((id) => id)
			this.selectedPublications = [
				...new Set([...this.selectedPublications, ...currentIds]),
			]
		},

		/**
		 * Clear all publication selections
		 */
		/** @spec openspec/specs/search/spec.md */
		clearAllSelections() {
			this.selectedPublications = []
		},

		/**
		 * Build query parameters for search
		 *
		 * @param {object} additionalParams - Additional parameters to include
		 * @return {object} Query parameters object
		 */
		/**
		 * @param additionalParams
		 * @spec openspec/specs/search/spec.md
		 */
		buildQueryParams(additionalParams = {}) {
			const params = {
				// Search term
				...(this.searchTerm && { q: this.searchTerm }),

				// Pagination
				_page: this.pagination.page,
				_limit: this.pagination.limit,

				// Filters
				...this.filters,

				// Ordering
				...(Object.keys(this.ordering).length > 0 && {
					_order: this.ordering,
				}),

				// Enable faceting and aggregation
				_facetable: true,
				_aggregate: this.aggregateByDefault,

				// Always include extended data
				_extend: ['@self.schema', '@self.register'],

				// Additional parameters
				...additionalParams,
			}

			// Remove empty values
			Object.keys(params).forEach((key) => {
				if (
					params[key] === ''
					|| params[key] === null
					|| params[key] === undefined
				) {
					delete params[key]
				}
			})

			return params
		},

		/**
		 * Build API URL with query parameters
		 *
		 * @param {string} endpoint - API endpoint (relative to base)
		 * @param {object} params - Query parameters
		 * @return {string} Complete API URL
		 */
		/**
		 * @param endpoint
		 * @param params
		 * @spec openspec/specs/search/spec.md
		 */
		buildApiUrl(endpoint, params = {}) {
			const queryString = new URLSearchParams()

			Object.entries(params).forEach(([key, value]) => {
				if (Array.isArray(value)) {
					value.forEach((v) => queryString.append(`${key}[]`, v))
				} else if (typeof value === 'object' && value !== null) {
					Object.entries(value).forEach(([subKey, subValue]) => {
						queryString.append(`${key}[${subKey}]`, subValue)
					})
				} else {
					queryString.append(key, value)
				}
			})

			const url = generateOcsUrl(endpoint)
			return queryString.toString() ? `${url}?${queryString.toString()}` : url
		},

		/**
		 * Perform search for publications
		 *
		 * @param {object} additionalParams - Additional search parameters
		 * @return {Promise} Search promise
		 */
		/**
		 * @param additionalParams
		 * @spec openspec/specs/search/spec.md
		 */
		async searchPublications(additionalParams = {}) {
			this.setLoading(true)
			this.setError(null)

			try {
				await runSearch(this, additionalParams)
			} catch (error) {
				console.error('Search error:', error)
				this.setError(error.message || 'Failed to search publications')
				this.setSearchResults([])
			} finally {
				this.setLoading(false)
			}
		},

		/**
		 * Load initial results without search term
		 *
		 * @return {Promise} Load promise
		 */
		/** @spec openspec/specs/search/spec.md */
		async loadInitialResults() {
			console.info('Loading initial search results')
			return this.searchPublications({ _page: 1 })
		},

		/**
		 * Clear search and reset to initial state
		 */
		/** @spec openspec/specs/search/spec.md */
		async clearSearch() {
			this.setSearchTerm('')
			this.clearAllFilters()
			this.clearOrdering()
			this.clearAllSelections()
			this.setPagination({ page: 1, limit: 20, total: 0, pages: 0, offset: 0 })

			// Load initial results
			await this.loadInitialResults()
		},

		/**
		 * Get publication by ID
		 *
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publication data
		 */
		/**
		 * @param id
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublication(id) {
			return getFederationPublication(id)
		},

		/**
		 * Get publications that this publication uses
		 *
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publications that this publication uses
		 */
		/**
		 * @param id
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublicationUses(id) {
			return getFederationPublication(id, 'uses')
		},

		/**
		 * Get publications that use this publication
		 *
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publications that use this publication
		 */
		/**
		 * @param id
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublicationUsed(id) {
			return getFederationPublication(id, 'used')
		},

		/**
		 * Get publication attachments
		 *
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publication attachments
		 */
		/**
		 * @param id
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublicationAttachments(id) {
			return getFederationPublication(id, 'attachments')
		},
	},
})
