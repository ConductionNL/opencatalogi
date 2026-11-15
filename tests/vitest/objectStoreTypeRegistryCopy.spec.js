/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the object store's type registry (register, unregister, settings
 * driven registration, schema lookup), the active object with its related
 * data, the collection preload and the object copy. Only `fetch` and the
 * inner lib store are stubbed; store actions that do HTTP are spied on.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

/**
 * A store whose inner lib store is `inner` and whose type mirror is recorded.
 *
 * @param {object} inner - The inner store stand-in
 * @return {object} The store
 */
function storeWithInner(inner = {}) {
	const store = useObjectStore()
	vi.spyOn(store, '_ensureInnerType').mockImplementation(() => {})
	vi.spyOn(store, '_inner').mockReturnValue(inner)
	return store
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('object store type registry', () => {
	it('registers a type once, with an empty collection, and fetches its collection', async () => {
		const store = storeWithInner()
		const fetchCollection = vi.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)

		await store.registerObjectType('event', '12', '4')
		await store.registerObjectType('event', '99', '99')

		expect(store.objectTypeRegistry.event).toEqual({ schema: '12', register: '4' })
		expect(store.collections.event).toEqual({ results: [] })
		expect(store._ensureInnerType).toHaveBeenCalledTimes(1)
		expect(store._ensureInnerType).toHaveBeenCalledWith('event')
		expect(fetchCollection).toHaveBeenCalledTimes(1)
		expect(fetchCollection).toHaveBeenCalledWith('event')
	})

	it('keeps an existing collection when registering', async () => {
		const store = storeWithInner()
		vi.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)
		store.collections = { event: { results: [{ id: 'kept' }] } }

		await store.registerObjectType('event', '12', '4')

		expect(store.collections.event).toEqual({ results: [{ id: 'kept' }] })
	})

	it('unregisters a type with its collection, active object and related data, in the inner store too', () => {
		const inner = { objectTypeRegistry: { event: {} }, unregisterObjectType: vi.fn() }
		const store = storeWithInner(inner)
		store.objectTypeRegistry = { event: { schema: '1', register: '2' }, theme: { schema: '3', register: '4' } }
		store.collections = { event: { results: [] }, theme: { results: [] } }
		store.activeObjects = { event: { id: 'e' } }
		store.relatedData = { event: { logs: [] } }

		store.unregisterObjectType('event')

		expect(Object.keys(store.objectTypeRegistry)).toEqual(['theme'])
		expect(Object.keys(store.collections)).toEqual(['theme'])
		expect(store.activeObjects).toEqual({})
		expect(store.relatedData).toEqual({})
		expect(inner.unregisterObjectType).toHaveBeenCalledWith('event')
	})

	it('leaves the inner store alone when it does not know the type, and ignores unknown types', () => {
		const inner = { objectTypeRegistry: {}, unregisterObjectType: vi.fn() }
		const store = storeWithInner(inner)
		store.objectTypeRegistry = { event: { schema: '1', register: '2' } }

		store.unregisterObjectType('event')
		store.unregisterObjectType('unknown')

		expect(store.objectTypeRegistry).toEqual({})
		expect(inner.unregisterObjectType).not.toHaveBeenCalled()
	})

	it('registers settings types from the explicit config first, then by schema slug or title', () => {
		const store = storeWithInner()
		store.objectTypeRegistry = { menu: { schema: 'kept', register: 'kept' } }
		store.settings = {
			objectTypes: ['catalog', 'theme', 'menu', 'orphan'],
			configuration: { catalog_register: 5, catalog_schema: 6 },
			availableRegisters: [
				{ id: 1, schemas: [{ id: 10, slug: 'other' }] },
				{ id: 2, schemas: [{ id: 20, slug: 'theme' }, { id: 21, title: 'Publication' }] },
			],
		}

		store._registerTypesFromSettings()

		expect(store.objectTypeRegistry).toEqual({
			menu: { schema: 'kept', register: 'kept' },
			catalog: { schema: '6', register: '5' },
			theme: { schema: '20', register: '2' },
			publication: { schema: '21', register: '2' },
		})
		expect(store._ensureInnerType.mock.calls.map((c) => c[0])).toEqual(['catalog', 'theme', 'publication'])
	})

	it('registers nothing without settings', () => {
		const store = storeWithInner()
		store.settings = null

		store._registerTypesFromSettings()

		expect(store.objectTypeRegistry).toEqual({})
	})

	it('answers the schema config from the registry, a late settings registration, or the settings', () => {
		const store = storeWithInner()
		expect(() => store.getSchemaConfig('theme')).toThrow('Settings not loaded')

		store.settings = {
			objectTypes: ['theme'],
			configuration: { theme_register: 1, theme_schema: 2, page_source: 'x', page_schema: 's', page_register: 'r' },
		}
		expect(store.getSchemaConfig('theme')).toEqual({ source: 'openregister', schema: '2', register: '1' })
		expect(store.getSchemaConfig('page')).toEqual({ source: 'x', schema: 's', register: 'r' })
		expect(() => store.getSchemaConfig('glossary')).toThrow('Invalid configuration for object type: glossary')
	})

	it('caches a fetched schema and answers null for a type without one', async () => {
		const inner = { fetchSchema: vi.fn().mockResolvedValue({ title: 'Theme' }) }
		const store = storeWithInner(inner)
		const fetchSettings = vi.spyOn(store, 'fetchSettings').mockImplementation(async () => {
			store.settings = { objectTypes: [], configuration: {} }
			store.objectTypeRegistry = { theme: { schema: '2', register: '1' } }
		})

		expect(await store.fetchSchema('theme')).toEqual({ title: 'Theme' })
		expect(await store.fetchSchema('theme')).toEqual({ title: 'Theme' })
		expect(await store.fetchSchema('unknown')).toBeNull()

		expect(fetchSettings).toHaveBeenCalledTimes(1)
		expect(inner.fetchSchema).toHaveBeenCalledTimes(1)
		expect(inner.fetchSchema).toHaveBeenCalledWith('theme')
		expect(store.schemas).toEqual({ theme: { title: 'Theme' } })
	})

	it('does not cache an empty schema answer', async () => {
		const inner = { fetchSchema: vi.fn().mockResolvedValue(null) }
		const store = storeWithInner(inner)
		store.settings = { objectTypes: [], configuration: {} }
		store.objectTypeRegistry = { theme: { schema: '2', register: '1' } }

		expect(await store.fetchSchema('theme')).toBeNull()
		expect(store.schemas).toEqual({})
	})
})

describe('object store settings and preload', () => {
	it('loads the settings and registers their types', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ objectTypes: ['theme'], configuration: { theme_register: 1, theme_schema: 2 } }),
		})
		vi.stubGlobal('fetch', fetchMock)
		const store = storeWithInner()

		await store.fetchSettings()

		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings')
		expect(store.settings.objectTypes).toEqual(['theme'])
		expect(store.objectTypeRegistry.theme).toEqual({ schema: '2', register: '1' })
	})

	it('throws when the settings cannot be read', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }))
		const store = storeWithInner()

		await expect(store.fetchSettings()).rejects.toThrow('Failed to fetch settings')
		expect(store.settings).toBeNull()
	})

	it('preloads every settings type and keeps going past a failing one', async () => {
		const store = storeWithInner()
		vi.spyOn(store, 'fetchSettings').mockImplementation(async () => {
			store.settings = { objectTypes: ['theme', 'page'], configuration: {} }
		})
		const fetchCollection = vi.spyOn(store, 'fetchCollection').mockImplementation(async (type) => {
			if (type === 'theme') throw new Error('down')
		})

		await store.preloadCollections()

		expect(fetchCollection.mock.calls.map((c) => c[0])).toEqual(['theme', 'page'])
		expect(console.warn).toHaveBeenCalledWith('Failed to preload collection for type theme:', expect.any(Error))
	})

	it('swallows a settings failure during preload', async () => {
		const store = storeWithInner()
		vi.spyOn(store, 'fetchSettings').mockRejectedValue(new Error('no settings'))
		const fetchCollection = vi.spyOn(store, 'fetchCollection')

		await expect(store.preloadCollections()).resolves.toBeUndefined()
		expect(fetchCollection).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith('Error during preload:', expect.any(Error))
	})
})

describe('object store active object', () => {
	it('sets the active object and fetches its four kinds of related data, with the publication\'s own register and schema', async () => {
		const store = storeWithInner()
		const related = vi.spyOn(store, 'fetchRelatedData').mockResolvedValue(undefined)
		const publication = { id: 'p1', '@self': { schema: 8, register: 3 } }

		await store.setActiveObject('publication', publication)

		expect(store.activeObjects.publication).toEqual(publication)
		expect(related.mock.calls.map((c) => c[0])).toEqual(['logs', 'uses', 'used', 'files'].map((dataType) => ({
			type: 'publication',
			id: 'p1',
			dataType,
			params: { _limit: dataType === 'files' ? 500 : 20, _page: 1 },
			publicationData: { source: 'openregister', schema: 8, register: 3 },
		})))
	})

	it('fetches related data without publication data for other types, and nothing for an object without id', async () => {
		const store = storeWithInner()
		const related = vi.spyOn(store, 'fetchRelatedData').mockResolvedValue(undefined)

		await store.setActiveObject('theme', { id: 't1', '@self': { schema: 1 } })
		expect(related).toHaveBeenCalledTimes(4)
		expect(related.mock.calls.every((c) => c[0].publicationData === null)).toBe(true)

		await store.setActiveObject('theme', null)
		expect(related).toHaveBeenCalledTimes(4)
		expect(store.activeObjects.theme).toBeNull()
		expect(store.relatedData.theme).toEqual({ logs: null, uses: null, used: null, files: null })
	})

	it('refreshes the active publication\'s files only for a complete publication', async () => {
		const store = storeWithInner()
		const related = vi.spyOn(store, 'fetchRelatedData').mockResolvedValue(undefined)

		store.activeObjects = { publication: { id: 'p1', '@self': { schema: 8 } } }
		await store.refreshActivePublicationFiles()
		expect(related).not.toHaveBeenCalled()

		store.activeObjects = { publication: { id: 'p1', '@self': { schema: { id: 8 }, register: '3' } } }
		await store.refreshActivePublicationFiles()
		expect(related).toHaveBeenCalledWith({
			type: 'publication',
			id: 'p1',
			dataType: 'files',
			params: {},
			publicationData: { source: 'openregister', schema: 8, register: '3' },
		})
	})
})

describe('object store copyObject', () => {
	/**
	 * A store holding `original` as theme 1, whose createObject answers the posted data.
	 *
	 * @param {object} original - The object to copy
	 * @return {{store: object, create: Function}} The store and its createObject spy
	 */
	function copyStore(original) {
		const store = storeWithInner()
		store.settings = { objectTypes: [], configuration: {} }
		store.objects = { theme: { 1: original } }
		const create = vi.spyOn(store, 'createObject').mockImplementation(async (type, data) => ({ id: 'new', ...data }))
		return { store, create }
	}

	it('posts a copy without id, prefixes the title and targets the source register and schema', async () => {
		const { store, create } = copyStore({ id: '1', title: 'Water', '@self': { register: 3, schema: 8, owner: 'x' }, n: 1 })

		const copy = await store.copyObject('theme', '1')

		expect(create).toHaveBeenCalledWith(
			'theme',
			{ title: 'Kopie van Water', n: 1, '@self': { register: 3, schema: 8 } },
			{ source: 'openregister', register: 3, schema: 8 },
		)
		expect(copy.id).toBe('new')
		expect(store.success.theme).toBe(true)
		expect(store.loading.theme_1_copy).toBe(false)
	})

	it('prefixes the name when there is no title, and sends no publication data without @self', async () => {
		const { store, create } = copyStore({ id: '1', name: 'Kaart' })

		await store.copyObject('theme', '1')

		expect(create).toHaveBeenCalledWith('theme', { name: 'Kopie van Kaart' }, null)
	})

	it('prefixes the configured name field at its dotted path', async () => {
		const { store, create } = copyStore({ id: '1', title: 'T', meta: { label: 'L' } })

		await store.copyObject('theme', '1', 'meta.label')

		expect(create.mock.calls[0][1]).toEqual({ title: 'T', meta: { label: 'Kopie van L' } })
	})

	it('falls back to the title when the configured path is a template or does not resolve', async () => {
		const first = copyStore({ id: '1', title: 'T', meta: null })
		await first.store.copyObject('theme', '1', 'meta.label')
		expect(first.create.mock.calls[0][1].title).toBe('Kopie van T')

		setActivePinia(createPinia())
		const second = copyStore({ id: '1', title: 'T', label: 'L' })
		await second.store.copyObject('theme', '1', '{{ label }}')
		expect(second.create.mock.calls[0][1]).toEqual({ title: 'Kopie van T', label: 'L' })
	})

	it('records and rethrows when the object is not cached', async () => {
		const { store, create } = copyStore({ id: '1' })

		await expect(store.copyObject('theme', '2')).rejects.toThrow('Object 2 of type theme not found')

		expect(create).not.toHaveBeenCalled()
		expect(store.errors.theme_2_copy).toBe('Object 2 of type theme not found')
		expect(store.success.theme).toBe(false)
		expect(store.loading.theme_2_copy).toBe(false)
	})
})
