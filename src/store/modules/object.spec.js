/* eslint-disable no-console */
import { createPinia, setActivePinia } from 'pinia'
import { useObjectStore } from './object.js'

/**
 * Mock settings response
 */
const mockSettings = {
	objectTypes: ['character', 'item', 'skill'],
	configuration: {
		character_source: 'openregister',
		character_schema: '105',
		character_register: '20',
		item_source: 'openregister',
		item_schema: '109',
		item_register: '20',
		skill_source: 'openregister',
		skill_schema: '110',
		skill_register: '20',
	},
}

/**
 * Mock collection response
 */
const mockCollection = {
	total: 2,
	page: 1,
	perPage: 10,
	results: [
		{ id: '1', name: 'Test 1' },
		{ id: '2', name: 'Test 2' },
	],
}

/**
 * Mock single object response
 */
const mockObject = {
	id: '1',
	name: 'Test Object',
	description: 'Test Description',
}

/**
 * Mock related data responses
 */
const mockRelatedData = {
	logs: {
		total: 1,
		page: 1,
		perPage: 10,
		results: [{ id: '1', action: 'create', timestamp: '2024-04-13T00:00:00Z' }],
	},
	uses: {
		total: 1,
		page: 1,
		perPage: 10,
		results: [{ id: '1', usedBy: 'character-1', timestamp: '2024-04-13T00:00:00Z' }],
	},
	used: {
		total: 1,
		page: 1,
		perPage: 10,
		results: [{ id: '1', usedIn: 'event-1', timestamp: '2024-04-13T00:00:00Z' }],
	},
	files: {
		total: 1,
		page: 1,
		perPage: 10,
		results: [{ id: '1', filename: 'test.pdf', size: 1024 }],
	},
}

// Mock fetch globally
global.fetch = jest.fn()

describe('ObjectStore', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		// Reset fetch mock
		fetch.mockReset()
	})

	describe('Settings', () => {
		it('fetches settings successfully', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockSettings),
			})

			await store.fetchSettings()

			expect(store.settings).toEqual(mockSettings)
			expect(store.objectTypes).toEqual(['character', 'item', 'skill'])
			expect(fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings')
		})

		it('handles settings fetch error', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
			})

			await expect(store.fetchSettings()).rejects.toThrow('Failed to fetch settings')
		})
	})

	describe('Schema Configuration', () => {
		beforeEach(async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockSettings),
			})
			await store.fetchSettings()
		})

		it('gets schema configuration for valid object type', () => {
			const config = store.getSchemaConfig('character')
			expect(config).toEqual({
				source: 'openregister',
				schema: '105',
				register: '20',
			})
		})

		it('throws error for invalid object type', () => {
			expect(() => store.getSchemaConfig('invalid')).toThrow('Invalid configuration for object type: invalid')
		})
	})

	describe('Collection Operations', () => {
		beforeEach(async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockSettings),
			})
			await store.fetchSettings()
		})

		it('fetches collection successfully', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockCollection),
			})

			await store.fetchCollection('character')

			// `collections[type]` holds the ENVELOPE, not a bare array — the store
			// defaults it to `{ results: [] }`, appends via `...collections[type].results`,
			// and SelectedObjectsList.vue reads `collection?.results`. This assertion
			// compared against the bare array, which the store has never stored.
			expect(store.collections.character).toEqual({ results: mockCollection.results })
			expect(store.objects.character).toEqual({
				1: { id: '1', name: 'Test 1' },
				2: { id: '2', name: 'Test 2' },
			})
			expect(store.isLoading('character')).toBe(false)
			expect(store.getError('character')).toBeNull()
		})

		it('handles collection fetch error', async () => {
			fetch.mockResolvedValueOnce({
				ok: false,
			})

			await expect(store.fetchCollection('character')).rejects.toThrow('Failed to fetch character collection')
			expect(store.isLoading('character')).toBe(false)
			expect(store.getError('character')).toBe('Failed to fetch character collection')
		})
	})

	describe('Single Object Operations', () => {
		beforeEach(async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockSettings),
			})
			await store.fetchSettings()
			// A write does not stop at the write: it refreshes the collection and
			// then calls setActiveObject(), which fans out to four related-data
			// fetches. Chaining a mockResolvedValueOnce per internal call makes the
			// spec a mirror of the store's call graph and it broke the moment that
			// graph changed — an unmocked fetch() resolved to `undefined` and the
			// worker died on `response.ok`. Give every UNSPECIFIED call a benign
			// default; the `Once` mocks above still take precedence for the calls
			// each test actually asserts on.
			fetch.mockResolvedValue({
				ok: true,
				json: () => Promise.resolve(mockCollection),
			})
		})

		it('fetches single object successfully', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockObject),
			})

			await store.fetchObject('character', '1')

			expect(store.objects.character['1']).toEqual(mockObject)
			expect(store.isLoading('character_1')).toBe(false)
			expect(store.getError('character_1')).toBeNull()
		})

		// Every write refreshes the collection afterwards ("Refresh the collection
		// to ensure it's up to date" in object.js), so each of these needs a
		// SECOND mocked response. Without it the follow-up fetch() resolved to
		// undefined and the store died on `response.ok`.
		it('creates object successfully', async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockObject),
			})
			// The refresh repopulates objects[type] FROM the collection, so the
			// collection has to contain the newly created row — otherwise the store
			// legitimately ends up holding whatever the collection returned.
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ ...mockCollection, results: [mockObject] }),
			})

			const newObject = await store.createObject('character', { name: 'New Character' })

			expect(newObject).toEqual(mockObject)
			expect(store.objects.character['1']).toEqual(mockObject)
			expect(store.isLoading('character_create')).toBe(false)
			expect(store.getError('character_create')).toBeNull()
		})

		it('updates object successfully', async () => {
			const updatedObject = { ...mockObject, name: 'Updated Name' }
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(updatedObject),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ ...mockCollection, results: [updatedObject] }),
			})

			const result = await store.updateObject('character', '1', { name: 'Updated Name' })

			expect(result).toEqual(updatedObject)
			expect(store.objects.character['1']).toEqual(updatedObject)
			expect(store.isLoading('character_1')).toBe(false)
			expect(store.getError('character_1')).toBeNull()
		})

		// `deleteObject` takes the OBJECT, not (type, id): it derives register and
		// schema from `@self` and DELETEs straight against OpenRegister's
		// /api/objects/{register}/{schema}/{id}. The old spec called
		// `deleteObject('character', '1')` — a signature the store no longer has —
		// and asserted on `collections[type]`, which delete does not touch.
		it('deletes object successfully', async () => {
			fetch.mockResolvedValueOnce({ ok: true })

			const objectItem = { ...mockObject, '@self': { id: '1', register: '20', schema: '105' } }
			store.setSelectedObjects([objectItem])

			await expect(store.deleteObject(objectItem)).resolves.toBe(true)

			expect(fetch).toHaveBeenCalledWith(
				'/index.php/apps/openregister/api/objects/20/105/1',
				{ method: 'DELETE' },
			)
			// Deleting an object drops it from the selection.
			expect(store.selectedObjects).toEqual([])
			expect(store.isLoading('delete_1')).toBe(false)
			expect(store.getError('delete_1')).toBeNull()
		})

		it('refuses to delete an object without register/schema information', async () => {
			await expect(store.deleteObject({ id: '1' })).rejects.toThrow(
				'Object must have id, register, and schema information',
			)
			expect(fetch).not.toHaveBeenCalledWith(
				expect.stringContaining('/api/objects/'),
				expect.objectContaining({ method: 'DELETE' }),
			)
		})
	})

	describe('Active Object Operations', () => {
		beforeEach(async () => {
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockSettings),
			})
			await store.fetchSettings()

			// Mock related data fetches
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.logs),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.uses),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.used),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.files),
			})
		})

		it('sets active object and fetches related data', async () => {
			await store.setActiveObject('character', mockObject)

			expect(store.activeObjects.character).toEqual(mockObject)
			expect(store.relatedData.character).toEqual({
				// `logs` alone is stored UNWRAPPED — the store keeps `data.results`
				// so `getAuditTrails()` can return an array directly. The other three
				// keep the full envelope (they carry pagination the UI reads).
				logs: mockRelatedData.logs.results,
				uses: mockRelatedData.uses,
				used: mockRelatedData.used,
				files: mockRelatedData.files,
			})
		})

		// Clearing NULLS the keys, it does not delete them: `delete obj[key]` is not
		// reactive in Vue 2, so the store keeps the key and empties it. The spec
		// asserted `toBeUndefined()`, which the store has never produced.
		it('clears active object and related data', async () => {
			await store.setActiveObject('character', mockObject)
			store.clearActiveObject('character')

			expect(store.activeObjects.character).toBeNull()
			expect(store.relatedData.character).toEqual({
				logs: null, uses: null, used: null, files: null,
			})
		})

		it('updates active object when fetching same object', async () => {
			await store.setActiveObject('character', mockObject)

			const updatedObject = { ...mockObject, name: 'Updated Name' }
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(updatedObject),
			})

			// Mock related data fetches again
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.logs),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.uses),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.used),
			})
			fetch.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve(mockRelatedData.files),
			})

			await store.fetchObject('character', '1')

			expect(store.activeObjects.character).toEqual(updatedObject)
		})

		// Four production call sites still delete via the legacy (type, id) pair —
		// DirectorySideBar, DeleteMultipleCategoriesDialog, ViewMenuModal and
		// ViewPageModal. After the signature moved to an object, every one of them
		// threw before issuing a request. The store now resolves the pair from
		// `objects[type][id]`, so these buttons work again.
		it('deletes via the legacy (type, id) pair used by the modals', async () => {
			const objectItem = { ...mockObject, '@self': { id: '1', register: '20', schema: '105' } }
			store.objects.character = { 1: objectItem }
			fetch.mockResolvedValueOnce({ ok: true })

			await expect(store.deleteObject('character', '1')).resolves.toBe(true)

			expect(fetch).toHaveBeenCalledWith(
				'/index.php/apps/openregister/api/objects/20/105/1',
				{ method: 'DELETE' },
			)
		})

		it('reports a clear error when the legacy pair names an object it has not loaded', async () => {
			await expect(store.deleteObject('character', 'nope')).rejects.toThrow(
				'Cannot delete character nope: object not loaded in the store',
			)
		})

		it('handles related data fetch error', async () => {
			fetch.mockReset()
			fetch.mockRejectedValueOnce(new Error('Network error'))

			await expect(store.fetchRelatedData('character', '1', 'logs')).rejects.toThrow()
			// The failure is recorded against the TYPE, not a per-request composite
			// key: the catch does `setState(type, { error })`. Asserting on
			// 'character_1_logs' read an error slot the store never writes, so it
			// was always null and the assertion could only pass by accident.
			expect(store.getError('character')).toBe('Network error')
			expect(store.isLoading('character_1_logs')).toBe(false)
		})
	})
})
