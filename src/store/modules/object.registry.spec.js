/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object store's type registry (registerObjectType, fetchSchema,
 * unregisterObjectType) and copyObject's naming and target rules. HTTP is the
 * only boundary doubled: the store's own collection and create calls are
 * spied on, the inner library store is the real one.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useObjectStore } from './object.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))

describe('Object store type registry', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('registers a type, gives it an empty collection, wires the inner store and fetches it', async () => {
		const fetchCollection = jest.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)

		await store.registerObjectType('listing', '105', '20')

		expect(store.objectTypeRegistry.listing).toEqual({ schema: '105', register: '20' })
		expect(store.collections.listing).toEqual({ results: [] })
		expect(store._inner().objectTypeRegistry.listing).toEqual(expect.objectContaining({ schema: '105', register: '20' }))
		expect(fetchCollection).toHaveBeenCalledWith('listing')
	})

	it('keeps an existing collection when a type is registered', async () => {
		jest.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)
		store.collections = { listing: { results: [{ id: 'kept' }] } }

		await store.registerObjectType('listing', '105', '20')

		expect(store.collections.listing).toEqual({ results: [{ id: 'kept' }] })
	})

	it('does nothing for a type that is already registered', async () => {
		const fetchCollection = jest.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)
		store.objectTypeRegistry = { listing: { schema: '1', register: '2' } }

		await store.registerObjectType('listing', '105', '20')

		expect(store.objectTypeRegistry.listing).toEqual({ schema: '1', register: '2' })
		expect(fetchCollection).not.toHaveBeenCalled()
	})

	it('unregisters a type with its collection, active object and related data, in the inner store too', async () => {
		jest.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)
		await store.registerObjectType('listing', '105', '20')
		store.objectTypeRegistry = { ...store.objectTypeRegistry, theme: { schema: '7', register: '20' } }
		store.activeObjects = { listing: { id: 'a' }, theme: { id: 't' } }
		store.relatedData = { listing: { logs: [] }, theme: { logs: [] } }
		store.collections = { ...store.collections, theme: { results: [] } }

		store.unregisterObjectType('listing')

		expect(Object.keys(store.objectTypeRegistry)).toEqual(['theme'])
		expect(store.collections.listing).toBeUndefined()
		expect(store.collections.theme).toEqual({ results: [] })
		expect(store.activeObjects).toEqual({ theme: { id: 't' } })
		expect(store.relatedData).toEqual({ theme: { logs: [] } })
		expect(store._inner().objectTypeRegistry.listing).toBeUndefined()
	})

	it('ignores unregistering a type it does not know', () => {
		store.objectTypeRegistry = { theme: { schema: '7', register: '20' } }
		store.collections = { theme: { results: [] } }

		store.unregisterObjectType('listing')

		expect(store.objectTypeRegistry).toEqual({ theme: { schema: '7', register: '20' } })
		expect(store.collections).toEqual({ theme: { results: [] } })
	})

	describe('fetchSchema', () => {
		it('answers a cached schema without fetching', async () => {
			store.schemas = { listing: { title: 'Listing' } }
			const fetchSettings = jest.spyOn(store, 'fetchSettings')

			await expect(store.fetchSchema('listing')).resolves.toEqual({ title: 'Listing' })
			expect(fetchSettings).not.toHaveBeenCalled()
		})

		it('loads the settings first when they are missing, and answers null for a type without a schema', async () => {
			const fetchSettings = jest.spyOn(store, 'fetchSettings').mockResolvedValue(undefined)

			await expect(store.fetchSchema('unknown')).resolves.toBeNull()
			expect(fetchSettings).toHaveBeenCalledTimes(1)
		})

		it('fetches the schema through the inner store and caches it', async () => {
			store.settings = {}
			store.objectTypeRegistry = { listing: { schema: '105', register: '20' } }
			const innerFetch = jest.spyOn(store._inner(), 'fetchSchema').mockResolvedValue({ title: 'Listing' })

			await expect(store.fetchSchema('listing')).resolves.toEqual({ title: 'Listing' })
			expect(innerFetch).toHaveBeenCalledWith('listing')
			expect(store.schemas.listing).toEqual({ title: 'Listing' })
		})

		it('does not cache a schema the inner store could not fetch', async () => {
			store.settings = {}
			store.objectTypeRegistry = { listing: { schema: '105', register: '20' } }
			jest.spyOn(store._inner(), 'fetchSchema').mockResolvedValue(null)

			await expect(store.fetchSchema('listing')).resolves.toBeNull()
			expect(store.schemas.listing).toBeUndefined()
		})
	})
})

describe('Object store copyObject', () => {
	let store
	let createObject

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		store.settings = {}
		jest.spyOn(console, 'error').mockImplementation(() => {})
		createObject = jest.spyOn(store, 'createObject').mockImplementation(async (_type, data) => ({ id: 'new', ...data }))
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('copies the title with "Kopie van", without the id, to the source register and schema', async () => {
		store.objects = { publication: { a: { id: 'a', title: 'Begroting', summary: 's', '@self': { register: 20, schema: 105, owner: 'x' } } } }

		const copy = await store.copyObject('publication', 'a')

		expect(createObject).toHaveBeenCalledWith(
			'publication',
			{ title: 'Kopie van Begroting', summary: 's', '@self': { register: 20, schema: 105 } },
			{ source: 'openregister', register: 20, schema: 105 },
		)
		expect(copy.id).toBe('new')
		expect(store.success.publication).toBe(true)
		expect(store.errors.publication).toBeNull()
		expect(store.isLoading('publication_a_copy')).toBe(false)
	})

	it('prefixes the name when there is no title, and has no target without @self', async () => {
		store.objects = { theme: { t: { id: 't', name: 'Wonen' } } }

		await store.copyObject('theme', 't')

		expect(createObject).toHaveBeenCalledWith('theme', { name: 'Kopie van Wonen' }, null)
	})

	it('prefixes the schema\'s configured name field, nested by dots', async () => {
		store.objects = { publication: { a: { id: 'a', title: 'T', meta: { label: 'Label' } } } }

		await store.copyObject('publication', 'a', 'meta.label')

		expect(createObject.mock.calls[0][1]).toEqual({ title: 'T', meta: { label: 'Kopie van Label' } })
	})

	it.each([
		['a path that does not resolve', 'meta.missing.label'],
		['a template', '{{ voornaam }} {{ achternaam }}'],
		['an empty path', ''],
	])('falls back to the title for %s', async (_case, path) => {
		store.objects = { publication: { a: { id: 'a', title: 'T', meta: { label: 'Label' } } } }

		await store.copyObject('publication', 'a', path)

		expect(createObject.mock.calls[0][1]).toEqual({ title: 'Kopie van T', meta: { label: 'Label' } })
	})

	it('falls back to the title when the configured field is not a non-empty string', async () => {
		store.objects = { publication: { a: { id: 'a', title: 'T', code: 7 } } }

		await store.copyObject('publication', 'a', 'code')

		expect(createObject.mock.calls[0][1]).toEqual({ title: 'Kopie van T', code: 7 })
	})

	it('keeps only the register or schema that @self has', async () => {
		store.objects = { publication: { a: { id: 'a', title: 'T', '@self': { schema: 105 } } } }

		await store.copyObject('publication', 'a')

		expect(createObject).toHaveBeenCalledWith(
			'publication',
			{ title: 'Kopie van T', '@self': { schema: 105 } },
			{ source: 'openregister', register: undefined, schema: 105 },
		)
	})

	it('loads the settings first when they are missing', async () => {
		store.settings = null
		const fetchSettings = jest.spyOn(store, 'fetchSettings').mockImplementation(async () => {
			store.settings = {}
		})
		store.objects = { theme: { t: { id: 't', name: 'Wonen' } } }

		await store.copyObject('theme', 't')

		expect(fetchSettings).toHaveBeenCalledTimes(1)
	})

	it('refuses an object it does not hold, and records the error', async () => {
		store.objects = {}

		await expect(store.copyObject('theme', 'x')).rejects.toThrow('Object x of type theme not found')

		expect(createObject).not.toHaveBeenCalled()
		expect(store.getError('theme_x_copy')).toBe('Object x of type theme not found')
		expect(store.success.theme).toBe(false)
		expect(store.errors.theme).toBe('Object x of type theme not found')
		expect(store.isLoading('theme_x_copy')).toBe(false)
	})
})
