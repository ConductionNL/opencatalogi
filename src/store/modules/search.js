/**
 * Pinia store for search functionality
 *
 * This store handles search operations for publications across local and federated sources.
 * It provides read-only access to publication data through federation endpoints.
 *
 * @category Store
 * @package
 * @author   Ruben van der Linde
 * @copyright 2024
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 * @link     https://github.com/opencatalogi/opencatalogi
 */

import { defineStore } from 'pinia'
import { generateOcsUrl } from '@nextcloud/router'

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
		 * @param state
		 * @return {Array} Array of search results
		 */
		getSearchResults: (state) => state.searchResults,

		/**
		 * Get current search term
		 * @param state
		 * @return {string} Current search term
		 */
		getSearchTerm: (state) => state.searchTerm,

		/**
		 * Get loading state
		 * @param state
		 * @return {boolean} Whether search is currently loading
		 */
		isSearchLoading: (state) => state.isLoading,

		/**
		 * Get error state
		 * @param state
		 * @return {string|null} Current error message or null
		 */
		getError: (state) => state.error,

		/**
		 * Get pagination information
		 * @param state
		 * @return {object} Pagination object with page, limit, total, etc.
		 */
		getPagination: (state) => state.pagination,

		/**
		 * Get current filters
		 * @param state
		 * @return {object} Current filter settings
		 */
		getFilters: (state) => state.filters,

		/**
		 * Get current facets
		 * @param state
		 * @return {object} Current facet data
		 */
		getFacets: (state) => state.facets,

		/**
		 * Get facetable metadata
		 * @param state
		 * @return {object} Facetable metadata for dynamic filters
		 */
		getFacetable: (state) => state.facetable,

		/**
		 * Get current ordering
		 * @param state
		 * @return {object} Current ordering settings
		 */
		getOrdering: (state) => state.ordering,

		/**
		 * Get current view mode
		 * @param state
		 * @return {string} Current view mode ('cards' or 'table')
		 */
		getViewMode: (state) => state.viewMode,

		/**
		 * Get selected publications
		 * @param state
		 * @return {Array} Array of selected publication IDs
		 */
		getSelectedPublications: (state) => state.selectedPublications,
	},

	actions: {
		/**
		 * Set search term
		 * @param {string} term - Search term to set
		 */
		setSearchTerm(term) {
			this.searchTerm = term
		},

		/**
		 * Set loading state
		 * @param {boolean} loading - Loading state
		 */
		setLoading(loading) {
			this.isLoading = loading
		},

		/**
		 * Set error state
		 * @param {string|null} error - Error message or null
		 */
		setError(error) {
			this.error = error
		},

		/**
		 * Set search results
		 * @param {Array} results - Search results array
		 */
		setSearchResults(results) {
			this.searchResults = results || []
		},

		/**
		 * Set pagination data
		 * @param {object} paginationData - Pagination information
		 */
		setPagination(paginationData) {
			this.pagination = {
				...this.pagination,
				...paginationData,
			}
		},

		/**
		 * Set filters
		 * @param {object} filters - Filter object to merge with existing filters
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},

		/**
		 * Clear a specific filter
		 * @param {string} key - Filter key to clear
		 */
		clearFilter(key) {
			const newFilters = { ...this.filters }
			delete newFilters[key]
			this.filters = newFilters
		},

		/**
		 * Clear all filters
		 */
		clearAllFilters() {
			this.filters = {}
		},

		/**
		 * Set facets data
		 * @param {object} facets - Facets data
		 */
		setFacets(facets) {
			this.facets = facets || {}
		},

		/**
		 * Set facetable metadata
		 * @param {object} facetable - Facetable metadata
		 */
		setFacetable(facetable) {
			this.facetable = facetable || {}
		},

		/**
		 * Set ordering for a field
		 * @param {string} field - Field to order by
		 * @param {string} direction - Direction ('ASC' or 'DESC')
		 */
		setOrdering(field, direction) {
			this.ordering = { ...this.ordering, [field]: direction }
		},

		/**
		 * Remove ordering for a field
		 * @param {string} field - Field to remove ordering for
		 */
		removeOrdering(field) {
			const newOrdering = { ...this.ordering }
			delete newOrdering[field]
			this.ordering = newOrdering
		},

		/**
		 * Clear all ordering
		 */
		clearOrdering() {
			this.ordering = {}
		},

		/**
		 * Set view mode
		 * @param {string} mode - View mode ('cards' or 'table')
		 */
		setViewMode(mode) {
			if (['cards', 'table'].includes(mode)) {
				this.viewMode = mode
			}
		},

		/**
		 * Toggle publication selection
		 * @param {string} id - Publication ID
		 * @param {boolean} selected - Whether to select or deselect
		 */
		togglePublicationSelection(id, selected) {
			if (selected && !this.selectedPublications.includes(id)) {
				this.selectedPublications.push(id)
			} else if (!selected) {
				this.selectedPublications = this.selectedPublications.filter(pubId => pubId !== id)
			}
		},

		/**
		 * Select all current publications
		 */
		selectAllPublications() {
			const currentIds = this.searchResults.map(pub => pub.id).filter(id => id)
			this.selectedPublications = [...new Set([...this.selectedPublications, ...currentIds])]
		},

		/**
		 * Clear all publication selections
		 */
		clearAllSelections() {
			this.selectedPublications = []
		},

		/**
		 * Build query parameters for search
		 * @param {object} additionalParams - Additional parameters to include
		 * @return {object} Query parameters object
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
				...(Object.keys(this.ordering).length > 0 && { _order: this.ordering }),

				// Enable faceting and aggregation
				_facetable: true,
				_aggregate: this.aggregateByDefault,

				// Always include extended data
				_extend: ['@self.schema', '@self.register'],

				// Additional parameters
				...additionalParams,
			}

			// Remove empty values
			Object.keys(params).forEach(key => {
				if (params[key] === '' || params[key] === null || params[key] === undefined) {
					delete params[key]
				}
			})

			return params
		},

		/**
		 * Build API URL with query parameters
		 * @param {string} endpoint - API endpoint (relative to base)
		 * @param {object} params - Query parameters
		 * @return {string} Complete API URL
		 */
		buildApiUrl(endpoint, params = {}) {
			const queryString = new URLSearchParams()

			Object.entries(params).forEach(([key, value]) => {
				if (Array.isArray(value)) {
					value.forEach(v => queryString.append(`${key}[]`, v))
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
		 * @param {object} additionalParams - Additional search parameters
		 * @return {Promise} Search promise
		 */
		async searchPublications(additionalParams = {}) {
			this.setLoading(true)
			this.setError(null)

			try {
				// Merge additional params with current state
				if (additionalParams._page) {
					this.pagination.page = additionalParams._page
				}
				if (additionalParams._limit) {
					this.pagination.limit = additionalParams._limit
				}

				const params = this.buildQueryParams(additionalParams)
				const url = this.buildApiUrl(this.searchEndpoint, params)

				console.info('Searching publications with URL:', url)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`Search failed with status ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()

				// Update state with response data
				this.setSearchResults(data.results || [])
				this.setPagination({
					page: data.page || 1,
					limit: data.limit || 20,
					total: data.total || 0,
					pages: data.pages || 1,
					offset: data.offset || 0,
				})

				// Update facets and facetable if present
				if (data.facets) {
					this.setFacets(data.facets)
				}
				if (data.facetable) {
					this.setFacetable(data.facetable)
				}

				console.info('Search completed:', {
					total: data.total,
					results: data.results?.length || 0,
					page: data.page,
				})

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
		 * @return {Promise} Load promise
		 */
		async loadInitialResults() {
			console.info('Loading initial search results')
			return this.searchPublications({ _page: 1 })
		},

		/**
		 * Clear search and reset to initial state
		 */
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
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publication data
		 */
		async getPublication(id) {
			if (!id) {
				throw new Error('Publication ID is required')
			}

			try {
				const url = generateOcsUrl(`/apps/opencatalogi/api/federation/publications/${id}`)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch publication: ${response.status} ${response.statusText}`)
				}

				return await response.json()

			} catch (error) {
				console.error('Error fetching publication:', error)
				throw error
			}
		},

		/**
		 * Get publications that this publication uses
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publications that this publication uses
		 */
		async getPublicationUses(id) {
			if (!id) {
				throw new Error('Publication ID is required')
			}

			try {
				const url = generateOcsUrl(`/apps/opencatalogi/api/federation/publications/${id}/uses`)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch publication uses: ${response.status} ${response.statusText}`)
				}

				return await response.json()

			} catch (error) {
				console.error('Error fetching publication uses:', error)
				throw error
			}
		},

		/**
		 * Get publications that use this publication
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publications that use this publication
		 */
		async getPublicationUsed(id) {
			if (!id) {
				throw new Error('Publication ID is required')
			}

			try {
				const url = generateOcsUrl(`/apps/opencatalogi/api/federation/publications/${id}/used`)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch publication used: ${response.status} ${response.statusText}`)
				}

				return await response.json()

			} catch (error) {
				console.error('Error fetching publication used:', error)
				throw error
			}
		},

		/**
		 * Get publication attachments
		 * @param {string} id - Publication ID
		 * @return {Promise<object>} Publication attachments
		 */
		async getPublicationAttachments(id) {
			if (!id) {
				throw new Error('Publication ID is required')
			}

			try {
				const url = generateOcsUrl(`/apps/opencatalogi/api/federation/publications/${id}/attachments`)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch publication attachments: ${response.status} ${response.statusText}`)
				}

				return await response.json()

			} catch (error) {
				console.error('Error fetching publication attachments:', error)
				throw error
			}
		},
	},
})
