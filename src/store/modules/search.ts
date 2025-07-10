/* eslint-disable no-console */
import { defineStore } from 'pinia'

// Type definitions for faceting
interface FacetFieldInfo {
	type: string
	description: string
	facet_types: string[]
	has_labels?: boolean
	sample_values?: Array<{ value: any, label: string, count: number }>
	appearance_rate?: number
	cardinality?: string
	intervals?: string[]
	date_range?: { min: string, max: string }
}

interface FacetableFields {
	'@self'?: Record<string, FacetFieldInfo>
	object_fields?: Record<string, FacetFieldInfo>
}

interface ActiveFacetConfig {
	type: string
	config: Record<string, any>
}

interface SearchState {
	// Search parameters
	searchTerm: string
	filters: Record<string, any>
	ordering: Record<string, 'ASC' | 'DESC'>

	// Results and pagination
	searchResults: any[]
	pagination: {
		page: number
		pages: number
		total: number
		limit: number
		offset: number
	}
	facets: Record<string, any>
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
		getFilters: (state): Record<string, any> => state.filters,
		getOrdering: (state): Record<string, 'ASC' | 'DESC'> => state.ordering,

		// Results getters
		getSearchResults: (state): any[] => state.searchResults,
		getPagination: (state) => state.pagination,
		getFacets: (state): Record<string, any> => state.facets,
		getFacetable: (state): FacetableFields => state.facetable,

		// Loading state getters
		isLoading: (state): boolean => state.loading,
		getError: (state): string | null => state.error,
		isFacetsLoading: (state): boolean => state.facetsLoading,

		// View mode getters
		getViewMode: (state): 'cards' | 'table' => state.viewMode,
		getSelectedPublications: (state): string[] => state.selectedPublications,

		// Facet management getters
		getActiveFacets: (state): Record<string, ActiveFacetConfig> => state.activeFacets,

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
				...state.facetable['@self'] || {},
				...state.facetable.object_fields || {},
			}
		},

		// Check if facets are available
		hasFacets: (state): boolean => {
			const metadataCount = Object.keys(state.facetable['@self'] || {}).length
			const objectFieldCount = Object.keys(state.facetable.object_fields || {}).length
			return metadataCount > 0 || objectFieldCount > 0
		},

		// Aliases for FacetComponent compatibility
		hasFacetableFields: (state): boolean => {
			const metadataCount = Object.keys(state.facetable['@self'] || {}).length
			const objectFieldCount = Object.keys(state.facetable.object_fields || {}).length
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
			console.log('hasActiveFacets getter called, count:', count, 'activeFacets:', state.activeFacets, 'keys:', activeFacetKeys)
			return count > 0
		},

		currentFacets: (state): Record<string, any> => {
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
		setSearchTerm(searchTerm: string) {
			this.searchTerm = searchTerm
			console.log('Search term set to:', searchTerm)
		},

		/**
		 * Set filters for the search
		 *
		 * @param filters Object containing filter key-value pairs
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
		clearFilter(filterKey: string) {
			const { [filterKey]: removed, ...remainingFilters } = this.filters
			this.filters = remainingFilters
			console.log('Filter cleared:', filterKey, 'Remaining filters:', this.filters)
		},

		/**
		 * Clear all filters
		 */
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
		setOrdering(field: string, direction: 'ASC' | 'DESC') {
			this.ordering = { ...this.ordering, [field]: direction }
			console.log('Ordering updated:', this.ordering)
		},

		/**
		 * Remove ordering for a field
		 *
		 * @param field The field to remove ordering from
		 */
		removeOrdering(field: string) {
			const { [field]: removed, ...remainingOrdering } = this.ordering
			this.ordering = remainingOrdering
			console.log('Ordering removed for field:', field, 'Remaining ordering:', this.ordering)
		},

		/**
		 * Clear all ordering
		 */
		clearOrdering() {
			this.ordering = {}
			console.log('All ordering cleared')
		},

		/**
		 * Set view mode (cards or table)
		 *
		 * @param mode The view mode to set
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
		togglePublicationSelection(publicationId: string, selected: boolean) {
			if (selected) {
				if (!this.selectedPublications.includes(publicationId)) {
					this.selectedPublications.push(publicationId)
				}
			} else {
				this.selectedPublications = this.selectedPublications.filter((id: string) => id !== publicationId)
			}
		},

		/**
		 * Select all publications
		 */
		selectAllPublications() {
			this.selectedPublications = this.searchResults.map((pub: any) => pub.id)
		},

		/**
		 * Clear all selections
		 */
		clearAllSelections() {
			this.selectedPublications = []
		},

		/**
		 * Discover facetable fields
		 *
		 * @param params Optional parameters for facetable discovery
		 */
		async discoverFacetableFields(params: Record<string, any> = {}) {
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

				console.log('Discovering facetable fields with params:', searchParams.toString())

				// Make API call to Federation endpoint for facetable discovery
				const response = await fetch(`/index.php/apps/opencatalogi/api/federation/publications?${searchParams.toString()}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

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
		toggleActiveFacet(fieldName: string, facetType: string, enabled: boolean, config: Record<string, any> = {}) {
			console.log('🔧 toggleActiveFacet called with:', {
				fieldName: typeof fieldName === 'string' ? `"${fieldName}"` : fieldName,
				facetType: typeof facetType === 'string' ? `"${facetType}"` : facetType,
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
				console.log('✅ Added active facet:', fieldName, '=', this.activeFacets[fieldName])
			} else {
				const { [fieldName]: removed, ...remainingFacets } = this.activeFacets
				this.activeFacets = remainingFacets
				console.log('❌ Removed active facet:', fieldName)
			}

			console.log('📋 All active facets after update:', this.activeFacets)

			// Automatically trigger search when facets change to get facet buckets
			if (Object.keys(this.activeFacets).length > 0) {
				console.log('🔍 Triggering search to get facet buckets for:', Object.keys(this.activeFacets))
				this.searchPublications()
			}
		},

		/**
		 * Clear all active facets
		 */
		clearAllActiveFacets() {
			this.activeFacets = {}
			console.log('All active facets cleared')
		},

		/**
		 * Build facet query configuration from active facets
		 *
		 * @return Facet query configuration object
		 */
		buildFacetQuery() {
			console.log('🏗️ buildFacetQuery() - Building from active facets:', this.activeFacets)

			const facetQuery: Record<string, any> = {
				'@self': {},
			}

			Object.entries(this.activeFacets).forEach(([fieldName, facetConfig]: [string, any]) => {
				console.log(`🔨 Processing field: "${fieldName}", config:`, facetConfig)

				if (fieldName.startsWith('@self.')) {
					// Metadata facet
					const metaField = fieldName.replace('@self.', '')
					console.log(`📊 Adding metadata facet: "${metaField}" from field "${fieldName}"`)
					facetQuery['@self'][metaField] = {
						type: facetConfig.type,
						...facetConfig.config,
					}
				} else {
					// Object field facet
					console.log(`📦 Adding object field facet: "${fieldName}"`)
					facetQuery[fieldName] = {
						type: facetConfig.type,
						...facetConfig.config,
					}
				}
			})

			console.log('🎯 Final facet query built:', facetQuery)
			return facetQuery
		},

		/**
		 * Load initial search results with facetable discovery
		 */
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
		 */
		async searchPublications(params: Record<string, any> = {}) {
			this.loading = true
			this.error = null

			try {
				// Build search parameters
				const searchParams = new URLSearchParams({
					// Add search term using _search for better compatibility with federated OpenCatalogi APIs
					...(this.searchTerm && { _search: this.searchTerm }),

					// Add pagination
					_page: (params._page as string) || this.pagination.page.toString(),
					_limit: (params._limit as string) || this.pagination.limit.toString(),

					// Enable facets and aggregation for federation
					_facetable: 'true',
					_aggregate: 'true',

					// Always include extended data
					'_extend[]': '@self.schema',

					// Add filters
					...this.filters,

					// Add any additional parameters
					...params,
				})

				// Add additional extend parameters if not already present
				if (!searchParams.has('_extend[]')) {
					searchParams.append('_extend[]', '@self.register')
				}

				// Add ordering parameters
				Object.entries(this.ordering).forEach(([field, direction]) => {
					searchParams.append(`_order[${field}]`, direction as string)
				})

				// Add facet queries if any active facets
				if (Object.keys(this.activeFacets).length > 0) {
					const facetQuery = this.buildFacetQuery()
					console.log('🔗 Converting facet query to URL parameters...')

					// Convert facet query to URL parameters
					Object.entries(facetQuery).forEach(([category, facets]) => {
						console.log(`🏷️ Processing category: "${category}", facets:`, facets)

						if (typeof facets === 'object' && facets !== null) {
							if (category === '@self') {
								// Handle @self metadata facets
								Object.entries(facets as Record<string, any>).forEach(([field, config]) => {
									console.log(`🎛️ Processing @self field: "${field}", config:`, config)

									// Add the type parameter
									const paramKey = `_facets[@self][${field}][type]`
									const paramValue = String(config.type)
									console.log(`🔧 Adding metadata facet param: ${paramKey} = "${paramValue}"`)
									searchParams.append(paramKey, paramValue)

									// Add additional config parameters (only if they are primitive values)
									Object.entries(config).forEach(([key, value]) => {
										if (key !== 'type' && value !== undefined && typeof value !== 'object') {
											const configParamKey = `_facets[@self][${field}][${key}]`
											const configParamValue = String(value)
											console.log(`🔧 Adding metadata facet config: ${configParamKey} = "${configParamValue}"`)
											searchParams.append(configParamKey, configParamValue)
										}
									})
								})
							} else {
								// Handle object field facets - category is the field name
								console.log(`🎛️ Processing object field: "${category}", config:`, facets)

								const facetConfig = facets as Record<string, any>

								// Add the type parameter - use category as the field name
								const paramKey = `_facets[${category}][type]`
								const paramValue = String(facetConfig.type)
								console.log(`🔧 Adding object field facet param: ${paramKey} = "${paramValue}"`)
								searchParams.append(paramKey, paramValue)

								// Add additional config parameters (only if they are primitive values)
								Object.entries(facetConfig).forEach(([key, value]) => {
									if (key !== 'type' && value !== undefined && typeof value !== 'object') {
										const configParamKey = `_facets[${category}][${key}]`
										const configParamValue = String(value)
										console.log(`🔧 Adding object field facet config: ${configParamKey} = "${configParamValue}"`)
										searchParams.append(configParamKey, configParamValue)
									}
								})
							}
						}
					})

					console.log('🌐 Final search params string:', searchParams.toString())
				}

				console.log('Searching publications with params:', searchParams.toString())
				console.log('Active facets before search:', this.activeFacets)
				console.log('Facet query built:', this.buildFacetQuery())

				// Debug facet parameter building
				if (Object.keys(this.activeFacets).length > 0) {
					console.log('Building facet parameters from active facets:')
					Object.entries(this.activeFacets).forEach(([fieldName, facetConfig]) => {
						console.log(`  Field: "${fieldName}", Config:`, facetConfig)
					})
				}

				// Make API call to Federation endpoint
				const response = await fetch(`/index.php/apps/opencatalogi/api/federation/publications?${searchParams.toString()}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Update state with results
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

			} catch (error) {
				console.error('Search failed:', error)
				this.error = error.message || 'An error occurred while searching'
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
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get publication details by ID
		 *
		 * @param publicationId The ID of the publication to fetch
		 */
		async getPublication(publicationId: string) {
			try {
				const response = await fetch(`/index.php/apps/opencatalogi/api/federation/publications/${publicationId}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
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
		async getPublicationUses(publicationId: string, params: Record<string, any> = {}) {
			try {
				const searchParams = new URLSearchParams(params as Record<string, string>)
				const response = await fetch(`/index.php/apps/opencatalogi/api/federation/publications/${publicationId}/uses?${searchParams.toString()}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
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
		async getPublicationUsed(publicationId: string, params: Record<string, any> = {}) {
			try {
				const searchParams = new URLSearchParams(params as Record<string, string>)
				const response = await fetch(`/index.php/apps/opencatalogi/api/federation/publications/${publicationId}/used?${searchParams.toString()}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
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
		clearSearch() {
			this.searchTerm = ''
			this.filters = {}
			this.ordering = {}
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
			this.activeFacets = {}
			this.error = null
			this.selectedPublications = []
			console.log('Search cleared')
		},
	},
})
