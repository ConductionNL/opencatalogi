import { defineStore } from 'pinia'
import { Catalogi } from '../../entities/catalogi/catalogi.ts'
import { objectStore } from '../../store/store.js'

/** @typedef {import('../../entities/catalogi/catalogi.ts').Catalogi} CatalogEntity */
/** @typedef {{id: string, title: string, [key: string]: any}} ObjectEntity */

/**
 * Store for managing catalogs and their publications in OpenCatalogi.
 * @module Store
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */
export const useCatalogStore = defineStore('catalog', {
	state: () => ({
		/** @type {import('../../entities/catalogi/catalogi.ts').Catalogi|null} */
		activeCatalog: null,

		/** @type {{id: string, title: string, [key: string]: any}|null} */
		activePublication: null,

		/** @type {{results: Array<any>, total: number, page: number, pages: number, limit: number, offset: number}} */
		publications: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},

		/** @type {Set<string>} */
		registeredTypes: new Set(),

		/** @type {boolean} */
		loading: false,

		/** @type {string} */
		viewMode: 'cards',

		/** @type {object} */
		pagination: {
			page: 1,
			limit: 20,
		},
	}),

	actions: {
		/**
		 * Set the view mode.
		 * @param {string} mode - The view mode to set ('cards' or 'table')
		 */
		setViewMode(mode) {
			this.viewMode = mode
			console.info('Catalog view mode set to:', mode)
		},

		/**
		 * Set pagination details.
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 20) {
			this.pagination = { page, limit }
			console.info('Catalog pagination set to', { page, limit })
		},

		/**
		 * Set the active catalog and fetch its publications
		 * @param {CatalogEntity} catalog The catalog to set as active
		 * @return {Promise<void>}
		 */
		async setActiveCatalog(catalog) {
			this.activeCatalog = new Catalogi(catalog)
			await this.fetchPublications()
		},

		async refreshPublications() {
			await this.fetchPublications()
		},

		/**
		 * Set the active publication
		 * @param {ObjectEntity} publication The publication to set as active
		 * @return {void}
		 */
		setActivePublication(publication) {
			this.activePublication = publication
		},

		/**
		 * Clear the active publication
		 * @return {void}
		 */
		clearActivePublication() {
			this.activePublication = null
		},

		/**
		 * Fetch publications for the active catalog
		 * @param {object} params - Optional parameters for pagination and filtering
		 * @param {number} params.page - Page number (default: 1)
		 * @param {number} params.limit - Items per page (default: 20)
		 * @return {Promise<void>}
		 */
		async fetchPublications(params = {}) {
			if (!this.activeCatalog) {
				return
			}

			this.loading = true
			objectStore.setLoading('publication', true)

			// Build query parameters for pagination
			const searchParams = {
				_page: params.page || this.pagination.page || 1,
				_limit: params.limit || this.pagination.limit || 20,
				_extend: '@self.schema,@self.register', // Always include schema and register info
			}

			// Add any additional parameters (excluding page and limit to avoid duplication)
			Object.keys(params).forEach(key => {
				if (key !== 'page' && key !== 'limit') {
					searchParams[key] = params[key]
				}
			})

			const queryParams = new URLSearchParams(searchParams)

			try {
				const url = `/index.php/apps/opencatalogi/api/catalogi/${this.activeCatalog.id}?${queryParams}`
				const response = await fetch(url)
				const data = await response.json()

				this.publications = {
					results: data.results || [],
					total: data.total || 0,
					page: data.page || 1,
					pages: data.pages || 0,
					limit: data.limit || 20,
					offset: data.offset || 0,
				}

				// Update internal pagination state
				this.pagination = {
					page: data.page || 1,
					limit: data.limit || 20,
				}

				// Process each publication to register its type in the object store
				for (const publication of data.results || []) {
					if (publication.schema && publication.register) {
						const slug = publication.schema.slug
						if (!this.registeredTypes.has(slug)) {
							await objectStore.registerObjectType(
								slug,
								publication.schema.id,
								publication.register.id,
							)
							this.registeredTypes.add(slug)
						}
					}
				}

				objectStore.setCollection('publication', data.results)
			} catch (error) {
				console.error('Error fetching publications:', error)
				this.publications = {
					results: [],
					total: 0,
					page: 1,
					pages: 0,
					limit: 20,
					offset: 0,
				}
			} finally {
				this.loading = false
				objectStore.setLoading('publication', false)
			}
		},

		async getPublicationAttachments() {
			const publication = objectStore.getActiveObject('publication')
			const register = publication['@self'].register
			const schema = publication['@self'].schema
			const id = publication.id

			const response = await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${id}/files`)
			const data = await response.json()
			objectStore.setCollection('publicationAttachments', data)
		},

		/**
		 * Clear the active catalog and its publications
		 * @return {void}
		 */
		clearActiveCatalog() {

			// Unregister all object types
			for (const slug of this.registeredTypes) {
				objectStore.unregisterObjectType(slug)
			}
			this.registeredTypes.clear()

			this.activeCatalog = null
			this.activePublication = null
			this.publications = {
				results: [],
				total: 0,
				page: 1,
				pages: 0,
				limit: 20,
				offset: 0,
			}
		},
	},

	getters: {
		/**
		 * Get the list of available registers from the active catalog
		 * @return {Array<string>} List of register IDs
		 */
		availableRegisters() {
			return this.activeCatalog?.registers || []
		},

		/**
		 * Get the list of available schemas from the active catalog
		 * @return {Array<string>} List of schema IDs
		 */
		availableSchemas() {
			return this.activeCatalog?.schemas || []
		},

		/**
		 * Check if a catalog is currently active
		 * @return {boolean} True if a catalog is active
		 */
		hasActiveCatalog() {
			return this.activeCatalog !== null
		},

		/**
		 * Check if a publication is currently active
		 * @return {boolean} True if a publication is active
		 */
		hasActivePublication() {
			return this.activePublication !== null
		},

		/**
		 * Get the active publication
		 * @param {object} state - Store state
		 * @return {object|null} The active publication
		 */
		getActivePublication: (state) => state.activePublication,

		/**
		 * Get loading state for specific type
		 * @param {object} state - Store state
		 * @return {boolean}
		 */
		isLoading: (state) => state.loading || false,

		/**
		 * Get the publications collection
		 * @param {object} state - The store state
		 * @return {object} The publications collection
		 */
		getPublications: (state) => {
			return state.publications || null
		},

		/**
		 * Get pagination info for publications
		 * @param {object} state - The store state
		 * @return {object} The pagination info
		 */
		publicationPagination: (state) => {
			return {
				page: state.publications.page || 1,
				pages: state.publications.pages || 0,
				total: state.publications.total || 0,
				limit: state.publications.limit || 20,
				offset: state.publications.offset || 0,
			}
		},
	},
})
