/* eslint-disable no-console */
import type { TPublication } from '../../entities/publication/publication.types'

import { defineStore } from 'pinia'

// Type definitions for faceting
interface FacetFieldInfo {
	type: string
	description: string
	facet_types: string[]
	has_labels?: boolean
	sample_values?: Array<{
		value: string | number | boolean
		label: string
		count: number
	}>
	appearance_rate?: number
	cardinality?: string
	intervals?: string[]
	date_range?: { min: string; max: string }
}

interface FacetableFields {
	'@self'?: Record<string, FacetFieldInfo>
	object_fields?: Record<string, FacetFieldInfo>
}

interface ActiveFacetConfig {
	type: string
	config: Record<string, string | number | boolean>
}

/** @typedef {{ type: string, config: object }} ActiveFacetConfig */

/** The body of a federation search response. */
interface SearchResponse {
	results?: TPublication[]
	page?: number
	pages?: number
	total?: number
	limit?: number
	offset?: number
	facets?: SearchState['facets']
	facetable?: FacetableFields
}

/** One facet's URL parameters: the key prefix, its config and the kind for the log. */
interface FacetParamSpec {
	prefix: string
	config: Record<string, unknown>
	kind: 'metadata' | 'object field'
}

/**
 * Append a facet's type and its primitive config values to the search params.
 *
 * @param searchParams The params to extend
 * @param facet The facet
 * @spec openspec/specs/search/spec.md
 */
function appendFacetParams(
	searchParams: URLSearchParams,
	facet: FacetParamSpec,
): void {
	const paramKey = `${facet.prefix}[type]`
	const paramValue = String(facet.config.type)
	console.log(`🔧 Adding ${facet.kind} facet param: ${paramKey} = "${paramValue}"`)
	searchParams.append(paramKey, paramValue)

	Object.entries(facet.config).forEach(([key, value]) => {
		if (key === 'type' || value === undefined || typeof value === 'object') {
			return
		}
		const configParamKey = `${facet.prefix}[${key}]`
		const configParamValue = String(value)
		console.log(
			`🔧 Adding ${facet.kind} facet config: ${configParamKey} = "${configParamValue}"`,
		)
		searchParams.append(configParamKey, configParamValue)
	})
}

/**
 * Append one facet query category: the `@self` metadata fields, or one object field.
 *
 * @param searchParams The params to extend
 * @param entry The category name and its facets
 * @spec openspec/specs/search/spec.md
 */
function appendFacetCategory(
	searchParams: URLSearchParams,
	[category, facets]: [string, unknown],
): void {
	console.log(`🏷️ Processing category: "${category}", facets:`, facets)
	if (typeof facets !== 'object' || facets === null) {
		return
	}
	if (category === '@self') {
		Object.entries(facets as Record<string, ActiveFacetConfig>).forEach(
			([field, config]) => {
				console.log(`🎛️ Processing @self field: "${field}", config:`, config)
				appendFacetParams(searchParams, {
					prefix: `_facets[@self][${field}]`,
					config: config as unknown as Record<string, unknown>,
					kind: 'metadata',
				})
			},
		)
		return
	}
	console.log(`🎛️ Processing object field: "${category}", config:`, facets)
	appendFacetParams(searchParams, {
		prefix: `_facets[${category}]`,
		config: facets as Record<string, unknown>,
		kind: 'object field',
	})
}

/**
 * GET a federation publications endpoint and parse its JSON body.
 *
 * @param suffix The path and query after `/api/federation/publications`
 * @return The parsed body
 * @throws {Error} When the response is not ok
 * @spec openspec/specs/search/spec.md
 */
async function getFederationJson(suffix: string) {
	const response = await fetch(
		`/index.php/apps/opencatalogi/api/federation/publications${suffix}`,
		{
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
			},
		},
	)

	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`)
	}

	return response.json()
}

/**
 * GET the federation publications search for the given params.
 *
 * @param searchParams The query
 * @return The parsed body
 * @spec openspec/specs/search/spec.md
 */
async function fetchFederationSearch(
	searchParams: URLSearchParams,
): Promise<SearchResponse> {
	return getFederationJson(`?${searchParams.toString()}`)
}

interface SearchState {
	// Search parameters
	searchTerm: string
	filters: Record<string, string | number | boolean>
	ordering: Record<string, 'ASC' | 'DESC'>

	// Results and pagination
	searchResults: TPublication[]
	pagination: {
		page: number
		pages: number
		total: number
		limit: number
		offset: number
	}
	facets: Record<
		string,
		Record<string, { buckets: Array<{ key: string; doc_count: number }> }>
	>
	facetable: FacetableFields

	// Facet management
	activeFacets: Record<string, ActiveFacetConfig>
	facetsLoading: boolean

	// Loading and error states
	loading: boolean
	error: string | null

	// View settings
	viewMode: 'cards' | 'table'
	selectedPublications: string[]
}

export const useSearchStore = defineStore('search', {
	state: (): SearchState => ({
		// Search parameters
		searchTerm: '',
		filters: {},
		ordering: {}, // New: ordering criteria { field: 'ASC'|'DESC' }

		// Results and pagination
		searchResults: [],
		pagination: {
			page: 1,
			pages: 1,
			total: 0,
			limit: 20,
			offset: 0,
		},
		facets: {},
		facetable: {},

		// Facet management
		activeFacets: {}, // Currently enabled facets { fieldName: { type: 'terms', config: {} } }
		facetsLoading: false, // Loading state for facet discovery

		// Loading and error states
		loading: false,
		error: null,

		// View settings
		viewMode: 'cards', // 'cards' or 'table'
		selectedPublications: [],
	}),

	getters: {
		// Search state getters
		getSearchTerm: (state): string => state.searchTerm,
		getFilters: (state): Record<string, string | number | boolean> =>
			state.filters,
		getOrdering: (state): Record<string, 'ASC' | 'DESC'> => state.ordering,

		// Results getters
		getSearchResults: (state): TPublication[] => state.searchResults,
		getPagination: (state) => state.pagination,
		getFacets: (
			state,
		): Record<
			string,
			Record<string, { buckets: Array<{ key: string; doc_count: number }> }>
		> => state.facets,
		getFacetable: (state): FacetableFields => state.facetable,

		// Loading state getters
		isLoading: (state): boolean => state.loading,
		getError: (state): string | null => state.error,
		isFacetsLoading: (state): boolean => state.facetsLoading,

		// View mode getters
		getViewMode: (state): 'cards' | 'table' => state.viewMode,
		getSelectedPublications: (state): string[] => state.selectedPublications,

		// Facet management getters
		getActiveFacets: (state): Record<string, ActiveFacetConfig> =>
			state.activeFacets,

		// Get metadata facets (from @self)
		getMetadataFacets: (state): Record<string, FacetFieldInfo> => {
			return state.facetable['@self'] || {}
		},

		// Get object field facets
		getObjectFieldFacets: (state): Record<string, FacetFieldInfo> => {
			return state.facetable.object_fields || {}
		},

		// Get all available facet fields
		getAllFacetFields: (state): Record<string, FacetFieldInfo> => {
			return {
				...(state.facetable['@self'] || {}),
				...(state.facetable.object_fields || {}),
			}
		},

		// Check if facets are available
		hasFacets: (state): boolean => {
			const metadataCount = Object.keys(state.facetable['@self'] || {}).length
			const objectFieldCount = Object.keys(
				state.facetable.object_fields || {},
			).length
			return metadataCount > 0 || objectFieldCount > 0
		},

		// Aliases for FacetComponent compatibility
		hasFacetableFields: (state): boolean => {
			const metadataCount = Object.keys(state.facetable['@self'] || {}).length
			const objectFieldCount = Object.keys(
				state.facetable.object_fields || {},
			).length
			return metadataCount > 0 || objectFieldCount > 0
		},

		availableMetadataFacets: (state): Record<string, FacetFieldInfo> => {
			return state.facetable['@self'] || {}
		},

		availableObjectFieldFacets: (state): Record<string, FacetFieldInfo> => {
			return state.facetable.object_fields || {}
		},

		hasActiveFacets: (state): boolean => {
			const activeFacetKeys = Object.keys(state.activeFacets)
			const count = activeFacetKeys.length
			console.log(
				'hasActiveFacets getter called, count:',
				count,
				'activeFacets:',
				state.activeFacets,
				'keys:',
				activeFacetKeys,
			)
			return count > 0
		},

		currentFacets: (
			state,
		): Record<
			string,
			Record<string, { buckets: Array<{ key: string; doc_count: number }> }>
		> => {
			return state.facets
		},

		// Check if there are actual facet results from search
		hasFacetResults: (state): boolean => {
			return Object.keys(state.facets).length > 0
		},
	},

	actions: {
		/**
		 * Set the search term
		 *
		 * @param searchTerm The search term to set
		 */
		/**
		 * @param searchTerm
		 * @spec openspec/specs/search/spec.md
		 */
		setSearchTerm(searchTerm: string) {
			this.searchTerm = searchTerm
			console.log('Search term set to:', searchTerm)
		},

		/**
		 * Set filters for the search
		 *
		 * @param filters Object containing filter key-value pairs
		 */
		/**
		 * @param filters
		 * @spec openspec/specs/search/spec.md
		 */
		setFilters(filters: object) {
			this.filters = { ...this.filters, ...filters }
			console.log('Search filters updated:', this.filters)
		},

		/**
		 * Clear a specific filter
		 *
		 * @param filterKey The key of the filter to clear
		 */
		/**
		 * @param filterKey
		 * @spec openspec/specs/search/spec.md
		 */
		clearFilter(filterKey: string) {
			const remainingFilters = { ...this.filters }
			delete remainingFilters[filterKey]
			this.filters = remainingFilters
			console.log(
				'Filter cleared:',
				filterKey,
				'Remaining filters:',
				this.filters,
			)
		},

		/**
		 * Clear all filters
		 */
		/** @spec openspec/specs/search/spec.md */
		clearAllFilters() {
			this.filters = {}
			console.log('All filters cleared')
		},

		/**
		 * Set ordering for a field
		 *
		 * @param field The field to order by
		 * @param direction ASC or DESC
		 */
		/**
		 * @param field
		 * @param direction
		 * @spec openspec/specs/search/spec.md
		 */
		setOrdering(field: string, direction: 'ASC' | 'DESC') {
			this.ordering = { ...this.ordering, [field]: direction }
			console.log('Ordering updated:', this.ordering)
		},

		/**
		 * Remove ordering for a field
		 *
		 * @param field The field to remove ordering from
		 */
		/**
		 * @param field
		 * @spec openspec/specs/search/spec.md
		 */
		removeOrdering(field: string) {
			const remainingOrdering = { ...this.ordering }
			delete remainingOrdering[field]
			this.ordering = remainingOrdering
			console.log(
				'Ordering removed for field:',
				field,
				'Remaining ordering:',
				this.ordering,
			)
		},

		/**
		 * Clear all ordering
		 */
		/** @spec openspec/specs/search/spec.md */
		clearOrdering() {
			this.ordering = {}
			console.log('All ordering cleared')
		},

		/**
		 * Set view mode (cards or table)
		 *
		 * @param mode The view mode to set
		 */
		/**
		 * @param mode
		 * @spec openspec/specs/search/spec.md
		 */
		setViewMode(mode: 'cards' | 'table') {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},

		/**
		 * Toggle selection of a publication
		 *
		 * @param publicationId The ID of the publication to toggle
		 * @param selected Whether the publication should be selected
		 */
		/**
		 * @param publicationId
		 * @param selected
		 * @spec openspec/specs/search/spec.md
		 */
		togglePublicationSelection(publicationId: string, selected: boolean) {
			if (selected) {
				if (!this.selectedPublications.includes(publicationId)) {
					this.selectedPublications.push(publicationId)
				}
			} else {
				this.selectedPublications = this.selectedPublications.filter(
					(id: string) => id !== publicationId,
				)
			}
		},

		/**
		 * Select all publications
		 */
		/** @spec openspec/specs/search/spec.md */
		selectAllPublications() {
			this.selectedPublications = this.searchResults.map(
				(pub: TPublication) => pub.id,
			)
		},

		/**
		 * Clear all selections
		 */
		/** @spec openspec/specs/search/spec.md */
		clearAllSelections() {
			this.selectedPublications = []
		},

		/**
		 * Discover facetable fields
		 *
		 * @param params Optional parameters for facetable discovery
		 * @spec openspec/specs/search/spec.md
		 */
		async discoverFacetableFields(
			params: Record<string, string | number | boolean> = {},
		) {
			this.facetsLoading = true

			try {
				// Build search parameters for facetable discovery
				const searchParams = new URLSearchParams({
					// Include current search context
					...(this.searchTerm && { _search: this.searchTerm }),

					// Request facetable field discovery
					_facetable: 'true',
					_aggregate: 'true',

					// Limit to 0 for discovery only (no actual results needed)
					_limit: '0',

					// Add current filters to get contextual facetable fields
					...this.filters,

					// Add any additional parameters
					...params,
				})

				console.log(
					'Discovering facetable fields with params:',
					searchParams.toString(),
				)

				// Make API call to Federation endpoint for facetable discovery
				const data = await fetchFederationSearch(searchParams)

				// Update facetable fields
				this.facetable = data.facetable || {}

				console.log('Facetable fields discovered:', this.facetable)
			} catch (error) {
				console.error('Failed to discover facetable fields:', error)
				this.facetable = {}
			} finally {
				this.facetsLoading = false
			}
		},

		/**
		 * Enable or disable a facet
		 *
		 * @param fieldName The field name (e.g., '@self.register', 'status')
		 * @param facetType The type of facet (terms, date_histogram, range)
		 * @param enabled Whether to enable or disable the facet
		 * @param config Optional configuration for the facet
		 */
		/**
		 * @param fieldName
		 * @param facetType
		 * @param enabled
		 * @param config
		 * @spec openspec/specs/search/spec.md
		 */
		toggleActiveFacet(
			fieldName: string,
			facetType: string,
			enabled: boolean,
			config: Record<string, string | number | boolean> = {},
		) {
			console.log('🔧 toggleActiveFacet called with:', {
				fieldName:
					typeof fieldName === 'string' ? `"${fieldName}"` : fieldName,
				facetType:
					typeof facetType === 'string' ? `"${facetType}"` : facetType,
				enabled,
				config,
				fieldNameType: typeof fieldName,
				facetTypeType: typeof facetType,
			})

			if (enabled) {
				this.activeFacets[fieldName] = {
					type: facetType,
					config,
				}
				console.log(
					'✅ Added active facet:',
					fieldName,
					'=',
					this.activeFacets[fieldName],
				)
			} else {
				const remainingFacets = { ...this.activeFacets }
				delete remainingFacets[fieldName]
				this.activeFacets = remainingFacets
				console.log('❌ Removed active facet:', fieldName)
			}

			console.log('📋 All active facets after update:', this.activeFacets)

			// Automatically trigger search when facets change to get facet buckets
			if (Object.keys(this.activeFacets).length > 0) {
				console.log(
					'🔍 Triggering search to get facet buckets for:',
					Object.keys(this.activeFacets),
				)
				this.searchPublications()
			}
		},

		/**
		 * Clear all active facets
		 */
		/** @spec openspec/specs/search/spec.md */
		clearAllActiveFacets() {
			this.activeFacets = {}
			console.log('All active facets cleared')
		},

		/**
		 * Build facet query configuration from active facets
		 *
		 * @return {Record<string, Record<string, ActiveFacetConfig> | ActiveFacetConfig>} Facet query configuration object
		 */
		/** @spec openspec/specs/search/spec.md */
		buildFacetQuery() {
			console.log(
				'🏗️ buildFacetQuery() - Building from active facets:',
				this.activeFacets,
			)

			const facetQuery: Record<
				string,
				Record<string, ActiveFacetConfig> | ActiveFacetConfig
			> = {
				'@self': {},
			}

			Object.entries(this.activeFacets).forEach(
				([fieldName, facetConfig]: [string, ActiveFacetConfig]) => {
					console.log(
						`🔨 Processing field: "${fieldName}", config:`,
						facetConfig,
					)

					if (fieldName.startsWith('@self.')) {
						// Metadata facet
						const metaField = fieldName.replace('@self.', '')
						console.log(
							`📊 Adding metadata facet: "${metaField}" from field "${fieldName}"`,
						)
						;(facetQuery['@self'] as Record<string, ActiveFacetConfig>)[
							metaField
						] = {
							type: facetConfig.type,
							config: facetConfig.config,
						}
					} else {
						// Object field facet
						console.log(`📦 Adding object field facet: "${fieldName}"`)
						facetQuery[fieldName] = {
							type: facetConfig.type,
							config: facetConfig.config,
						}
					}
				},
			)

			console.log('🎯 Final facet query built:', facetQuery)
			return facetQuery
		},

		/**
		 * Load initial search results with facetable discovery
		 */
		/** @spec openspec/specs/search/spec.md */
		async loadInitialResults() {
			console.log('Loading initial search results with facets...')

			// First discover facetable fields
			await this.discoverFacetableFields()

			// Then load results
			await this.searchPublications({ _limit: 20, _page: 1 })
		},

		/**
		 * Perform a search using the SearchController API
		 *
		 * @param params Optional search parameters
		 * @spec openspec/specs/search/spec.md
		 */
		async searchPublications(
			params: Record<string, string | number | boolean> = {},
		) {
			this.loading = true
			this.error = null

			try {
				const searchParams = this.buildSearchParams(params)
				this.logSearchDebug(searchParams)
				const data = await fetchFederationSearch(searchParams)
				this.applySearchResults(data)
				this.logSearchResults(data)
			} catch (error) {
				console.error('Search failed:', error)
				this.error =
					(error instanceof Error && error.message)
					|| 'An error occurred while searching'
				this.resetSearchResults()
			} finally {
				this.loading = false
			}
		},

		/**
		 * The base query of a search: term, pagination, facet flags, filters and `params`.
		 *
		 * @param params Extra parameters, which win over the defaults
		 * @return The query as a record
		 * @spec openspec/specs/search/spec.md
		 */
		searchQuery(params: Record<string, string | number | boolean>) {
			return {
				// Add search term using _search for better compatibility with federated OpenCatalogi APIs
				...(this.searchTerm && { _search: this.searchTerm }),

				// Add pagination
				_page: (params._page as string) || this.pagination.page.toString(),
				_limit:
					(params._limit as string) || this.pagination.limit.toString(),

				// Enable facets and aggregation for federation
				_facetable: 'true',
				_aggregate: 'true',

				// Always include extended data
				'_extend[]': '@self.schema',

				...this.filters,
				...params,
			}
		},

		/**
		 * The URL parameters of a search, with ordering and active facets.
		 *
		 * @param params Extra parameters
		 * @return The search params
		 * @spec openspec/specs/search/spec.md
		 */
		buildSearchParams(params: Record<string, string | number | boolean>) {
			const searchParams = new URLSearchParams(
				this.searchQuery(params) as Record<string, string>,
			)

			if (!searchParams.has('_extend[]')) {
				searchParams.append('_extend[]', '@self.register')
			}

			Object.entries(this.ordering).forEach(([field, direction]) => {
				searchParams.append(`_order[${field}]`, direction as string)
			})

			if (Object.keys(this.activeFacets).length > 0) {
				this.appendActiveFacets(searchParams)
			}
			return searchParams
		},

		/**
		 * Append the active facets, as built by buildFacetQuery, to the search params.
		 *
		 * @param searchParams The params to extend
		 * @spec openspec/specs/search/spec.md
		 */
		appendActiveFacets(searchParams: URLSearchParams) {
			const facetQuery = this.buildFacetQuery()
			console.log('🔗 Converting facet query to URL parameters...')
			Object.entries(facetQuery).forEach((entry) =>
				appendFacetCategory(searchParams, entry),
			)
			console.log('🌐 Final search params string:', searchParams.toString())
		},

		/**
		 * Log the search about to be sent.
		 *
		 * @param searchParams The search params
		 * @spec openspec/specs/search/spec.md
		 */
		logSearchDebug(searchParams: URLSearchParams) {
			console.log(
				'Searching publications with params:',
				searchParams.toString(),
			)
			console.log('Active facets before search:', this.activeFacets)
			console.log('Facet query built:', this.buildFacetQuery())

			if (Object.keys(this.activeFacets).length > 0) {
				console.log('Building facet parameters from active facets:')
				Object.entries(this.activeFacets).forEach(
					([fieldName, facetConfig]) => {
						console.log(`  Field: "${fieldName}", Config:`, facetConfig)
					},
				)
			}
		},

		/**
		 * Store a search response: results, pagination, facets and facetable fields.
		 *
		 * @param data The response body
		 * @spec openspec/specs/search/spec.md
		 */
		applySearchResults(data: SearchResponse) {
			this.searchResults = data.results || []
			this.pagination = {
				page: data.page || 1,
				pages: data.pages || 1,
				total: data.total || 0,
				limit: data.limit || 20,
				offset: data.offset || 0,
			}
			this.facets = data.facets || {}
			this.facetable = data.facetable || {}
		},

		/**
		 * Log a stored search response.
		 *
		 * @param data The response body
		 * @spec openspec/specs/search/spec.md
		 */
		logSearchResults(data: SearchResponse) {
			console.log('API Response facets:', data.facets)
			console.log('API Response facetable:', data.facetable)
			console.log('Stored facets after update:', this.facets)

			console.log('Search completed successfully:', {
				results: this.searchResults.length,
				pagination: this.pagination,
				facets: Object.keys(this.facets).length,
				facetable: Object.keys(this.facetable).length,
				activeFacets: Object.keys(this.activeFacets).length,
				ordering: this.ordering,
			})
		},

		/**
		 * Empty the results after a failed search.
		 *
		 * @spec openspec/specs/search/spec.md
		 */
		resetSearchResults() {
			this.searchResults = []
			this.pagination = {
				page: 1,
				pages: 1,
				total: 0,
				limit: 20,
				offset: 0,
			}
			this.facets = {}
			this.facetable = {}
		},

		/**
		 * Get publication details by ID
		 *
		 * @param publicationId The ID of the publication to fetch
		 */
		/**
		 * @param publicationId
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublication(publicationId: string) {
			try {
				const data = await getFederationJson(`/${publicationId}`)
				console.log('Publication fetched:', data)
				return data
			} catch (error) {
				console.error('Failed to fetch publication:', error)
				throw error
			}
		},

		/**
		 * Get publications that this publication uses/references
		 *
		 * @param publicationId The ID of the publication
		 * @param params Optional search parameters
		 */
		/**
		 * @param publicationId
		 * @param params
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublicationUses(
			publicationId: string,
			params: Record<string, string | number | boolean> = {},
		) {
			try {
				const searchParams = new URLSearchParams(
					params as Record<string, string>,
				)
				const data = await getFederationJson(
					`/${publicationId}/uses?${searchParams.toString()}`,
				)
				console.log('Publication uses fetched:', data)
				return data
			} catch (error) {
				console.error('Failed to fetch publication uses:', error)
				throw error
			}
		},

		/**
		 * Get publications that use/reference this publication
		 *
		 * @param publicationId The ID of the publication
		 * @param params Optional search parameters
		 */
		/**
		 * @param publicationId
		 * @param params
		 * @spec openspec/specs/search/spec.md
		 */
		async getPublicationUsed(
			publicationId: string,
			params: Record<string, string | number | boolean> = {},
		) {
			try {
				const searchParams = new URLSearchParams(
					params as Record<string, string>,
				)
				const data = await getFederationJson(
					`/${publicationId}/used?${searchParams.toString()}`,
				)
				console.log('Publication used by fetched:', data)
				return data
			} catch (error) {
				console.error('Failed to fetch publication used by:', error)
				throw error
			}
		},

		/**
		 * Clear search results and reset state
		 */
		/** @spec openspec/specs/search/spec.md */
		clearSearch() {
			this.searchTerm = ''
			this.filters = {}
			this.ordering = {}
			this.resetSearchResults()
			this.activeFacets = {}
			this.error = null
			this.selectedPublications = []
			console.log('Search cleared')
		},
	},
})
