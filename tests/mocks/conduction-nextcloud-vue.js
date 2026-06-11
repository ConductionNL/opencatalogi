/**
 * Jest mock for `@conduction/nextcloud-vue`.
 *
 * The real barrel pulls in `@nextcloud/vue` (CSS + ESM modules) which Jest
 * cannot evaluate without a cross-cutting babel/transformIgnorePatterns
 * rewrite. Opencatalogi's store-only spec doesn't need the real lib — it
 * mocks `fetch` and tests the outer wrapper's bookkeeping. So this mock
 * provides minimal stubs:
 *
 *   - `createObjectStore(id, options)` returns a Pinia store factory whose
 *     instance exposes the methods the outer wrapper calls
 *     (`registerObjectType`, `unregisterObjectType`, `fetchObject`,
 *     `fetchSchema`, plus a couple of getters). The fetch URL building
 *     mirrors the lib enough that mocked `fetch` calls receive the
 *     expected URL shape.
 *   - The 6 plugin functions return empty plugin descriptors.
 *
 * If new tests start exercising lib methods that aren't stubbed here,
 * extend the file rather than reaching for transformIgnorePatterns.
 */
const { defineStore } = require('pinia')

const baseUrl = '/index.php/apps/openregister/api/objects'

function createObjectStore(id, options = {}) {
	const _options = options || {}
	void _options
	return defineStore(id, {
		state: () => ({
			objectTypeRegistry: {},
			collections: {},
			pagination: {},
			schemas: {},
			registers: {},
			facets: {},
			objects: {},
			loading: {},
			errors: {},
		}),
		actions: {
			registerObjectType(slug, schemaId, registerId) {
				this.objectTypeRegistry = {
					...this.objectTypeRegistry,
					[slug]: { schema: schemaId, register: registerId },
				}
			},
			unregisterObjectType(slug) {
				const { [slug]: _, ...rest } = this.objectTypeRegistry
				this.objectTypeRegistry = rest
			},
			async fetchObject(type, id) {
				const config = this.objectTypeRegistry[type]
				if (!config) return null
				const url = `${baseUrl}/${config.register}/${config.schema}/${id}`
				const response = await fetch(url, { method: 'GET' })
				if (!response.ok) return null
				const data = await response.json()
				if (!this.objects[type]) this.objects[type] = {}
				this.objects[type][id] = data
				return data
			},
			async fetchSchema(type) {
				const config = this.objectTypeRegistry[type]
				if (!config) return null
				if (this.schemas[type]) return this.schemas[type]
				const response = await fetch(`/apps/openregister/api/schemas/${config.schema}`, { method: 'GET' })
				if (!response.ok) return null
				const data = await response.json()
				this.schemas = { ...this.schemas, [type]: data }
				return data
			},
			getError(type) {
				return this.errors[type] || null
			},
		},
	})
}

const noopPlugin = () => ({ name: 'noop', state: () => ({}), getters: {}, actions: {} })

module.exports = {
	createObjectStore,
	useObjectStore: createObjectStore('conduction-objects'),
	auditTrailsPlugin: noopPlugin,
	filesPlugin: noopPlugin,
	lifecyclePlugin: noopPlugin,
	liveUpdatesPlugin: noopPlugin,
	relationsPlugin: noopPlugin,
	selectionPlugin: noopPlugin,
	logsPlugin: noopPlugin,
	registerMappingPlugin: noopPlugin,
	searchPlugin: noopPlugin,
	buildHeaders: () => ({ 'Content-Type': 'application/json' }),
	buildQueryString: (params) => {
		const u = new URLSearchParams(params || {})
		return u.toString() ? `?${u.toString()}` : ''
	},
}
