import { buildHeaders } from '@conduction/nextcloud-vue'
import { defineStore } from 'pinia'
import { Catalogi } from '../../entities/catalogi/catalogi.ts'
import { objectStore } from '../../store/store.js'

/** @typedef {import('../../entities/catalogi/catalogi.ts').Catalogi} CatalogEntity */

/**
 * The query for a page of a catalog's publications: page and limit from the
 * params or the current pagination, schema and register always extended, and
 * every other param passed on.
 *
 * @param {object} params - The caller's params; `page` and `limit` become `_page` and `_limit`
 * @param {{page: number, limit: number}} pagination - The current pagination
 * @return {object} The search params
 */
function publicationSearchParams(params, pagination) {
	const searchParams = {
		_page: params.page || pagination.page || 1,
		_limit: params.limit || pagination.limit || 20,
		_extend: '@self.schema,@self.register',
	}

	Object.keys(params).forEach((key) => {
		if (key !== 'page' && key !== 'limit') {
			searchParams[key] = params[key]
		}
	})

	return searchParams
}

/**
 * A page of publications from the API answer, with a default for every missing field.
 *
 * @param {object} data - The API answer
 * @return {{results: Array, total: number, page: number, pages: number, limit: number, offset: number}} The page
 */
function publicationsPage(data) {
	return {
		results: data.results || [],
		total: data.total || 0,
		page: data.page || 1,
		pages: data.pages || 0,
		limit: data.limit || 20,
		offset: data.offset || 0,
	}
}

/**
 * A schema or register reference resolved to its object: an embedded object
 * as it is, an id through the map (by string key, then by number key).
 *
 * @param {object|string|number} ref - The reference
 * @param {object} map - Resolved objects keyed by id
 * @return {object|undefined} The object, when known
 */
function resolveRef(ref, map) {
	if (typeof ref === 'object') {
		return ref
	}

	return map[String(ref)] || map[Number(ref)]
}
/** @typedef {{id: string, title: string, [key: string]: any}} ObjectEntity */

/**
 * Store for managing catalogs and their publications in OpenCatalogi.
 *
 * @module Store
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
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

		/** @type {string|null} */
		lastCatalogId: null,

		/** @type {{[key: string]: {id: number, slug: string, title: string}}} */
		schemas: {},

		/** @type {{[key: string]: {id: number, slug: string, title: string}}} */
		registers: {},
	}),

	actions: {
		/**
		 * Set the view mode.
		 *
		 * @param {string} mode - The view mode to set ('cards' or 'table')
		 */
		/**
		 * @param mode
		 * @spec openspec/specs/catalogs/spec.md
		 */
		setViewMode(mode) {
			this.viewMode = mode
			console.info('Catalog view mode set to:', mode)
		},

		/**
		 * Set pagination details.
		 *
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		/**
		 * @param page
		 * @param limit
		 * @spec openspec/specs/catalogs/spec.md
		 */
		setPagination(page, limit = 20) {
			this.pagination = { page, limit }
			console.info('Catalog pagination set to', { page, limit })
		},

		/**
		 * Set the active catalog and fetch its publications
		 *
		 * @param {CatalogEntity} catalog The catalog to set as active
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/catalogs/spec.md
		 */
		async setActiveCatalog(catalog) {
			this.activeCatalog = new Catalogi(catalog)
			await this.fetchPublications()
		},

		/** @spec openspec/specs/catalogs/spec.md */
		async refreshPublications() {
			await this.fetchPublications()
		},

		/**
		 * Set the active publication
		 *
		 * @param {ObjectEntity} publication The publication to set as active
		 * @return {void}
		 */
		setActivePublication(publication) {
			this.activePublication = publication
		},

		/**
		 * Clear the active publication
		 *
		 * @return {void}
		 */
		/** @spec openspec/specs/catalogs/spec.md */
		clearActivePublication() {
			this.activePublication = null
		},

		/**
		 * Fetch publications for a catalog
		 *
		 * @param {object} params - Optional parameters for pagination and filtering
		 * @param {number} params.page - Page number (default: 1)
		 * @param {number} params.limit - Items per page (default: 20)
		 * @param {string|null} catalogId - The ID of the catalog to fetch publications for, if null the active catalog is used
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/catalogs/spec.md
		 */
		async fetchPublications(params = {}, catalogId = null) {
			const resolvedCatalogId =
				catalogId
				|| this.activeCatalog?.slug
				|| this.activeCatalog?.id
				|| this.lastCatalogId
			if (!resolvedCatalogId) {
				console.error(
					'[CatalogStore#fetchPublications] No catalog ID provided and no active catalog exists',
				)
				return
			}

			this.lastCatalogId = resolvedCatalogId

			this.loading = true
			objectStore.setLoading('publication', true)

			const queryParams = new URLSearchParams(
				publicationSearchParams(params, this.pagination),
			)

			try {
				const catalogIdToUse = resolvedCatalogId

				// Use the slug-based publications endpoint (GET /api/{catalogSlug})
				const url = `/index.php/apps/opencatalogi/api/${catalogIdToUse}?${queryParams}`
				const response = await fetch(url, {
					method: 'GET',
					headers: buildHeaders(),
				})
				const data = await response.json()

				this.publications = publicationsPage(data)
				this.pagination = {
					page: this.publications.page,
					limit: this.publications.limit,
				}

				await this.registerPublicationTypes(data)

				objectStore.setCollection('publication', data.results)
			} catch (error) {
				console.error('Error fetching publications:', error)
				this.publications = publicationsPage({})
			} finally {
				this.loading = false
				objectStore.setLoading('publication', false)
			}
		},

		/**
		 * Register the object type of each publication in a page with the object store, once per schema slug.
		 *
		 * The backend returns per-publication @self.schema / @self.register as IDs,
		 * with the resolved schema/register objects on data['@self'].schemas / .registers
		 * (keyed by id). Older shapes embedded the full object on the publication itself.
		 *
		 * @param {object} data - The publications page from the API
		 * @return {Promise<void>}
		 * @spec openspec/specs/catalogs/spec.md
		 */
		async registerPublicationTypes(data) {
			const schemasMap = data['@self']?.schemas || {}
			const registersMap = data['@self']?.registers || {}
			for (const publication of data.results || []) {
				const schemaRef = publication['@self']?.schema ?? publication.schema
				const registerRef =
					publication['@self']?.register ?? publication.register
				if (!schemaRef || !registerRef) continue

				const schemaObj = resolveRef(schemaRef, schemasMap)
				const registerObj = resolveRef(registerRef, registersMap)
				const slug = schemaObj?.slug

				if (slug && !this.registeredTypes.has(slug)) {
					await objectStore.registerObjectType(
						slug,
						schemaObj?.id ?? schemaRef,
						registerObj?.id ?? registerRef,
					)
					this.registeredTypes.add(slug)
				}
			}
		},

		/** @spec openspec/specs/catalogs/spec.md */
		async getPublicationAttachments() {
			const publication = objectStore.getActiveObject('publication')
			const register = publication['@self'].register
			const schema = publication['@self'].schema
			const id = publication.id

			const response = await fetch(
				`/index.php/apps/openregister/api/objects/${register}/${schema}/${id}/files`,
			)
			const data = await response.json()
			objectStore.setCollection('publicationAttachments', data.results || [])
		},

		/**
		 * Clear the active catalog and its publications
		 *
		 * @return {void}
		 */
		/** @spec openspec/specs/catalogs/spec.md */
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
		 *
		 * @return {Array<string>} List of register IDs
		 */
		/** @spec openspec/specs/catalogs/spec.md */
		availableRegisters() {
			return this.activeCatalog?.registers || []
		},

		/**
		 * Get the list of available schemas from the active catalog
		 *
		 * @return {Array<string>} List of schema IDs
		 */
		/** @spec openspec/specs/catalogs/spec.md */
		availableSchemas() {
			return this.activeCatalog?.schemas || []
		},

		/**
		 * Check if a catalog is currently active
		 *
		 * @return {boolean} True if a catalog is active
		 */
		hasActiveCatalog() {
			return this.activeCatalog !== null
		},

		/**
		 * Check if a publication is currently active
		 *
		 * @return {boolean} True if a publication is active
		 */
		hasActivePublication() {
			return this.activePublication !== null
		},

		/**
		 * Get the active publication
		 *
		 * @param {object} state - Store state
		 * @return {object|null} The active publication
		 */
		getActivePublication: (state) => state.activePublication,

		/**
		 * Get loading state for specific type
		 *
		 * @param {object} state - Store state
		 * @return {boolean}
		 */
		isLoading: (state) => state.loading || false,

		/**
		 * Get the publications collection
		 *
		 * @param {object} state - The store state
		 * @return {object} The publications collection
		 */
		getPublications: (state) => {
			return state.publications || null
		},

		/**
		 * Get pagination info for publications
		 *
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
