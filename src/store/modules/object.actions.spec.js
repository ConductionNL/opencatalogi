/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { createPinia, setActivePinia } from 'pinia'
import { useObjectStore } from './object.js'

// @nextcloud/auth ships ESM that Jest does not transform; the store only
// reads the request token from it, so stub that boundary.
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))

const OBJECTS = '/index.php/apps/openregister/api/objects'

/**
 * Stub fetch with one response for every call.
 *
 * @param {object} body The JSON body
 * @param {boolean} ok Whether the response is ok
 * @return {jest.Mock} The mock
 */
function answerWith(body, ok = true) {
	global.fetch = jest.fn(() => Promise.resolve({
		ok,
		status: ok ? 200 : 500,
		statusText: ok ? 'OK' : 'Server Error',
		json: () => Promise.resolve(body),
	}))
	return global.fetch
}

/**
 * An object with register and schema on `@self`.
 *
 * @param {string} id The object id
 * @return {object} The object
 */
function item(id) {
	return { id, '@self': { register: { id: 20 }, schema: 105 } }
}

describe('Object store actions', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'info').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	describe('single object actions', () => {
		it('publishes an object, refreshes the active publication and drops it from the selection', async () => {
			const fetch = answerWith({ id: 'a', published: true })
			store.activeObjects = { publication: { id: 'a' } }
			store.selectedObjects = [{ id: 'a' }, { id: 'b' }]

			await expect(store.publishObject(item('a'))).resolves.toEqual({ id: 'a', published: true })

			expect(fetch).toHaveBeenCalledWith(`${OBJECTS}/20/105/a/publish`, {
				method: 'POST',
				headers: { requesttoken: 'tok' },
			})
			expect(store.activeObjects.publication).toEqual({ id: 'a', published: true })
			expect(store.selectedObjects).toEqual([{ id: 'b' }])
			expect(store.loading.publish_a).toBe(false)
		})

		it('depublishes an object without touching an unrelated active publication', async () => {
			const fetch = answerWith({ id: 'a' })
			store.activeObjects = { publication: { id: 'other', '@self': { id: 'other' } } }
			await store.depublishObject(item('a'))
			expect(fetch.mock.calls[0][0]).toBe(`${OBJECTS}/20/105/a/depublish`)
			expect(store.activeObjects.publication.id).toBe('other')
		})

		it('locks with a JSON body when a process or duration is given, and keeps the selection', async () => {
			const fetch = answerWith({ id: 'a' })
			store.selectedObjects = [{ id: 'a' }]
			await store.lockObject(item('a'), 'review', 60)
			expect(fetch).toHaveBeenCalledWith(`${OBJECTS}/20/105/a/lock`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
				body: JSON.stringify({ process: 'review', duration: 60 }),
			})
			expect(store.selectedObjects).toEqual([{ id: 'a' }])

			await store.lockObject(item('a'))
			expect(fetch.mock.calls[1][1]).toEqual({ method: 'POST', headers: { requesttoken: 'tok' }, body: undefined })

			await store.unlockObject(item('a'))
			expect(fetch.mock.calls[2][0]).toBe(`${OBJECTS}/20/105/a/unlock`)
		})

		it('records the error of a failed action and rethrows it', async () => {
			answerWith({}, false)
			await expect(store.publishObject(item('a'))).rejects.toThrow('Failed to publish object: 500 Server Error')
			expect(store.errors.publish_a).toBe('Failed to publish object: 500 Server Error')
			expect(store.loading.publish_a).toBe(false)
		})

		it('refuses an object without register, schema or ids it can extract', () => {
			expect(() => store.objectTarget({ id: 'a' })).toThrow('Object must have id, register, and schema information')
			expect(() => store.objectTarget({ id: 'a', register: {}, schema: 1 })).toThrow('Could not extract register or schema ID')
			expect(store.objectTarget({ '@self': { id: 'x' }, register: 'r', schema: { uuid: 's' } }))
				.toEqual({ objectId: 'x', registerId: 'r', schemaId: 's' })
		})

		it('extracts ids from values and objects', () => {
			expect(store.extractId(null)).toBeNull()
			expect(store.extractId(undefined)).toBeUndefined()
			expect(store.extractId(7)).toBe(7)
			expect(store.extractId({ id: 1 })).toBe(1)
			expect(store.extractId({ uuid: 'u' })).toBe('u')
			expect(store.extractId({ _id: 'i' })).toBe('i')
		})

		it('validates an object by saving it to its register and schema', async () => {
			const save = jest.spyOn(store, 'saveObject').mockResolvedValue({ data: { id: 'a', valid: true } })
			store.activeObjects = { publication: { '@self': { id: 'a' } } }
			await expect(store.validateObject(item('a'))).resolves.toEqual({ id: 'a', valid: true })
			expect(save).toHaveBeenCalledWith(item('a'), { register: 20, schema: 105 })
			expect(store.activeObjects.publication).toEqual({ id: 'a', valid: true })
		})
	})

	describe('bulk actions', () => {
		it('reports successes and failures per object and keeps only failed ones selected', async () => {
			jest.spyOn(store, 'publishObject').mockImplementation((obj) =>
				obj.id === 'bad' ? Promise.reject(new Error('nope')) : Promise.resolve(obj))
			store.selectedObjects = ['a', 'bad']
			const progress = jest.fn()

			const result = await store.massPublishObjects([{ id: 'a' }, { id: 'bad' }], progress)

			expect(result.successful).toEqual([{ success: true, id: 'a', object: { id: 'a' } }])
			expect(result.failed).toEqual([{ success: false, id: 'bad', object: { id: 'bad' }, error: 'nope' }])
			expect(store.selectedObjects).toEqual(['bad'])
			expect(store.getObjectError('bad')).toBe('nope')
			expect(store.getObjectError('a')).toBeNull()
			expect(progress).toHaveBeenCalledWith({ id: 'a' }, true)
			expect(progress).toHaveBeenCalledWith({ id: 'bad' }, false, 'nope')
		})

		it('falls back to a generic message for errors without one', async () => {
			jest.spyOn(store, 'unlockObject').mockRejectedValue({})
			const result = await store.massUnlockObjects([{ '@self': { id: 's' } }])
			expect(result.failed[0]).toEqual(expect.objectContaining({ id: 's', error: 'Unknown error' }))
			expect(store.selectedObjects).toEqual([])
		})

		it('deletes ids given as strings or objects', async () => {
			const del = jest.spyOn(store, 'deleteObject').mockResolvedValue(true)
			const result = await store.massDeleteObjects(['a', { id: 'b' }])
			expect(del.mock.calls).toEqual([[{ id: 'a' }], [{ id: 'b' }]])
			expect(result.successful.map((r) => r.id)).toEqual(['a', 'b'])
		})

		it('routes each bulk verb to its single action', async () => {
			const dep = jest.spyOn(store, 'depublishObject').mockResolvedValue({})
			const val = jest.spyOn(store, 'validateObject').mockResolvedValue({})
			const lock = jest.spyOn(store, 'lockObject').mockResolvedValue({})
			await store.massDepublishObjects([{ id: 'a' }])
			await store.massValidateObjects([{ id: 'a' }])
			await store.massLockObjects([{ id: 'a' }], 'p', 5)
			expect(dep).toHaveBeenCalledWith({ id: 'a' })
			expect(val).toHaveBeenCalledWith({ id: 'a' })
			expect(lock).toHaveBeenCalledWith({ id: 'a' }, 'p', 5)
		})
	})

	describe('attachments', () => {
		beforeEach(() => {
			store.activeObjects = { publication: { id: 'p', '@self': { register: 20, schema: { id: 105 } } } }
		})

		it('publishes an attachment and reloads the publication files', async () => {
			const fetch = answerWith({})
			const related = jest.spyOn(store, 'fetchRelatedData').mockResolvedValue()
			await expect(store.publishAttachment(9)).resolves.toBe(true)
			expect(fetch).toHaveBeenCalledWith(`${OBJECTS}/20/105/p/files/9/publish`, {
				method: 'POST',
				headers: { requesttoken: 'tok' },
			})
			expect(related).toHaveBeenCalledWith({
				type: 'publication',
				id: 'p',
				dataType: 'files',
				params: {},
				publicationData: { source: 'openregister', schema: 105, register: 20 },
			})
			expect(store.loading.publish_file_9).toBe(false)
		})

		it('records a failed depublish and rethrows it', async () => {
			answerWith({}, false)
			await expect(store.depublishAttachment(9)).rejects.toThrow('Failed to depublish file 9: 500 Server Error')
			expect(store.errors.depublish_file_9).toBe('Failed to depublish file 9: 500 Server Error')
		})

		it('refuses without a complete active publication', async () => {
			store.activeObjects = { publication: { id: 'p' } }
			await expect(store.publishAttachment(1)).rejects.toThrow('Active publication is not set or missing register/schema')
			const related = jest.spyOn(store, 'fetchRelatedData')
			await store.refreshActivePublicationFiles()
			expect(related).not.toHaveBeenCalled()
		})

		it('bulk-publishes attachments and drops the successful ones from the selection', async () => {
			jest.spyOn(store, 'publishAttachment').mockImplementation((id) =>
				id === 2 ? Promise.reject(new Error('locked')) : Promise.resolve(true))
			store.selectedAttachments = [1, 2, 3]
			const progress = jest.fn()
			const result = await store.massPublishAttachments([1, 2], progress)
			expect(result.successful).toEqual([{ success: true, id: 1 }])
			expect(result.failed).toEqual([{ success: false, id: 2, error: 'locked' }])
			expect(store.selectedAttachments).toEqual([2, 3])
			expect(progress).toHaveBeenCalledWith(2, false, 'locked')
		})

		it('does nothing for an empty or missing id list', async () => {
			const dep = jest.spyOn(store, 'depublishAttachment')
			await expect(store.massDepublishAttachments([])).resolves.toEqual({ successful: [], failed: [] })
			await expect(store.massDepublishAttachments(null)).resolves.toEqual({ successful: [], failed: [] })
			expect(dep).not.toHaveBeenCalled()
		})

		it('bulk-depublishes attachments', async () => {
			jest.spyOn(store, 'depublishAttachment').mockResolvedValue(true)
			store.selectedAttachments = undefined
			const result = await store.massDepublishAttachments([4])
			expect(result.successful).toEqual([{ success: true, id: 4 }])
			expect(store.selectedAttachments).toEqual([])
		})
	})

	describe('selection and columns', () => {
		it('toggles select-all over the publication collection', () => {
			store.toggleSelectAllObjects()
			expect(store.selectedObjects).toEqual([])

			store.collections = { publication: { results: [{ id: 'a' }, { '@self': { id: 'b' } }] } }
			expect(store.isAllSelected).toBe(false)
			store.toggleSelectAllObjects()
			expect(store.selectedObjects).toEqual(['a', 'b'])
			expect(store.isAllSelected).toBe(true)
			store.toggleSelectAllObjects()
			expect(store.selectedObjects).toEqual([])
		})

		it('manages per-object errors', () => {
			store.setObjectError('a', 'x')
			store.setObjectError('b', 'y')
			store.clearObjectError('a')
			expect(store.objectErrors).toEqual({ b: 'y' })
			store.clearAllObjectErrors()
			expect(store.objectErrors).toEqual({})
		})

		it('builds properties from a schema and column filters from both sets', () => {
			store.initializeProperties(null)
			expect(store.properties).toEqual({})

			store.initializeProperties({ properties: { naam: { title: 'Naam' }, code: { description: 'De code' } } })
			expect(store.properties).toEqual({
				naam: { label: 'Naam', key: 'naam', description: 'Property: naam', enabled: false },
				code: { label: 'code', key: 'code', description: 'De code', enabled: false },
			})

			store.metadata = { title: { enabled: true } }
			store.initializeColumnFilters()
			expect(store.columnFilters).toEqual({ meta_title: true, prop_naam: false, prop_code: false })
		})

		it('updates a column filter and the column it names', () => {
			store.metadata = { title: { enabled: true } }
			store.properties = { naam: { enabled: false } }
			store.updateColumnFilter('meta_title', false)
			store.updateColumnFilter('prop_naam', true)
			store.updateColumnFilter('prop_unknown', true)
			store.updateColumnFilter('meta_unknown', true)
			store.updateColumnFilter('other', true)
			expect(store.metadata.title.enabled).toBe(false)
			expect(store.properties.naam.enabled).toBe(true)
			expect(store.columnFilters).toEqual(expect.objectContaining({ meta_title: false, prop_naam: true, other: true }))
		})

		it('sets success and error state only for the given fields', () => {
			store.setState('x', { success: true })
			store.setState('x', { error: 'e' })
			expect(store.success.x).toBe(true)
			expect(store.errors.x).toBe('e')
		})
	})

	describe('search terms and paging', () => {
		it('debounces a search term into one collection fetch', () => {
			jest.useFakeTimers()
			const fetchCollection = jest.spyOn(store, 'fetchCollection').mockResolvedValue()
			store.setSearchTerm('page', 'a')
			store.setSearchTerm('page', 'ab')
			jest.advanceTimersByTime(500)
			expect(fetchCollection).toHaveBeenCalledTimes(1)
			expect(fetchCollection).toHaveBeenCalledWith('page', { _search: 'ab' })

			store.setSearchTerm('page', '')
			jest.advanceTimersByTime(500)
			expect(fetchCollection).toHaveBeenLastCalledWith('page', {})
		})

		it('clears a search term, cancelling a pending search', () => {
			jest.useFakeTimers()
			const fetchCollection = jest.spyOn(store, 'fetchCollection').mockResolvedValue()
			store.setSearchTerm('menu', 'x')
			store.clearSearchTerm('menu')
			jest.advanceTimersByTime(500)
			expect(fetchCollection).toHaveBeenCalledTimes(1)
			expect(fetchCollection).toHaveBeenCalledWith('menu')
			expect(store.searchTerms.menu).toBe('')

			store.clearSearchTerm('glossary')
			expect(fetchCollection).toHaveBeenLastCalledWith('glossary')
		})

		it('loads the next and previous page from links or page numbers', async () => {
			const fetchCollection = jest.spyOn(store, 'fetchCollection').mockResolvedValue()

			store.setPagination('page', { page: 2, pages: 3, limit: 10 })
			await store.loadMore('page')
			await store.loadPrevious('page')
			expect(fetchCollection.mock.calls).toEqual([
				['page', { _page: 3, _limit: 10 }, true],
				['page', { _page: 1, _limit: 10 }, false],
			])

			store.setPagination('page', {
				next: 'https://x.test/api?_page=4&_limit=5',
				prev: 'https://x.test/api?_page=2&_limit=5',
			})
			await store.loadMore('page')
			await store.loadPrevious('page')
			expect(fetchCollection.mock.calls.slice(2)).toEqual([
				['page', { _page: '4', _limit: '5' }, true],
				['page', { _page: '2', _limit: '5' }, false],
			])

			store.setPagination('page', { page: 1, pages: 1, limit: 10 })
			await store.loadMore('page')
			await store.loadPrevious('page')
			expect(fetchCollection).toHaveBeenCalledTimes(4)
		})
	})

	describe('getters', () => {
		it('derives object types, registers and flattened schemas from settings', () => {
			expect(store.objectTypes).toEqual([])
			expect(store.availableRegisters).toEqual([])
			expect(store.availableSchemas).toEqual([])
			store.settings = {
				objectTypes: ['page'],
				availableRegisters: [{ id: 1, title: 'R', schemas: [{ id: 2, title: 'S' }] }],
			}
			expect(store.objectTypes).toEqual(['page'])
			expect(store.availableRegisters).toHaveLength(1)
			expect(store.availableSchemas).toEqual([{ id: 2, title: 'S', registerId: 1, registerTitle: 'R' }])
		})

		it('answers defaults for unknown types', () => {
			expect(store.isLoading('x')).toBe(false)
			expect(store.getError('x')).toBeNull()
			expect(store.getCollection('x')).toEqual({ results: [] })
			expect(store.getSearchTerm('x')).toBe('')
			expect(store.getObject('x', 1)).toBeNull()
			expect(store.getActiveObject('x')).toBeNull()
			expect(store.getRelatedData('x', 'logs')).toBeNull()
			expect(store.getAuditTrails('x')).toEqual([])
			expect(store.getState('x')).toEqual({ success: null, error: null })
			expect(store.getObjectType('x')).toBeNull()
			expect(store.hasObjectType('x')).toBe(false)
			expect(store.getPagination('x')).toEqual({ total: 0, page: 1, pages: 1, limit: 20 })
			expect(store.getPagination('publication_files')).toEqual({ total: 0, page: 1, pages: 1, limit: 500 })
			expect(store.hasMorePages('x')).toBe(false)
			expect(store.hasPreviousPages('x')).toBe(false)
		})

		it('answers stored values for known types', () => {
			store.objects = { page: { 1: { id: 1 } } }
			store.relatedData = { page: { logs: ['l'] } }
			store.objectTypeRegistry = { page: { schema: '1', register: '2' } }
			store.searchTerms = { page: 'q' }
			expect(store.getObject('page', 1)).toEqual({ id: 1 })
			expect(store.getAuditTrails('page')).toEqual(['l'])
			expect(store.getRelatedData('page', 'logs')).toEqual(['l'])
			expect(store.hasObjectType('page')).toBe(true)
			expect(store.getObjectType('page')).toEqual({ schema: '1', register: '2' })
			expect(store.getSearchTerm('page')).toBe('q')
		})

		it('tells whether there are more or previous pages', () => {
			store.pagination = {
				a: { page: 1, pages: 2, next: null, prev: null },
				b: { page: 2, pages: 2, next: null, prev: null },
				c: { page: 1, pages: 1, next: 'n', prev: 'p' },
			}
			expect(store.hasMorePages('a')).toBe(true)
			expect(store.hasPreviousPages('a')).toBe(false)
			expect(store.hasMorePages('b')).toBe(false)
			expect(store.hasPreviousPages('b')).toBe(true)
			expect(store.hasMorePages('c')).toBe(true)
			expect(store.hasPreviousPages('c')).toBe(true)
		})

		it('lists the enabled metadata and property columns', () => {
			store.metadata = { title: { label: 'T', enabled: true }, uuid: { label: 'U', enabled: false } }
			store.properties = { naam: { label: 'N', enabled: true }, code: { label: 'C', enabled: false } }
			expect(store.enabledMetadata).toEqual([{ id: 'meta_title', label: 'T', enabled: true }])
			expect(store.enabledProperties).toEqual([{ id: 'prop_naam', key: 'naam', label: 'N', enabled: true }])
			expect(store.enabledColumns.map((c) => c.id)).toEqual(['meta_title', 'prop_naam'])
		})
	})

	describe('saveObject', () => {
		it('requires an object, a register and a schema', async () => {
			await expect(store.saveObject(null, { register: 1, schema: 2 })).rejects.toThrow('Object item, register and schema are required')
			await expect(store.saveObject({}, { register: 1 })).rejects.toThrow('Object item, register and schema are required')
			await expect(store.saveObject({}, { register: {}, schema: 2 })).rejects.toThrow('Could not extract register or schema ID')
		})

		it('POSTs a new object to the collection and stamps it updated', async () => {
			const fetch = answerWith({ id: 'new' })
			const objectItem = { title: 'T' }
			await expect(store.saveObject(objectItem, { register: { id: 1 }, schema: 2 }))
				.resolves.toEqual(expect.objectContaining({ data: { id: 'new' } }))
			expect(fetch.mock.calls[0][0]).toBe(`${OBJECTS}/1/2`)
			expect(fetch.mock.calls[0][1].method).toBe('POST')
			expect(typeof objectItem['@self'].updated).toBe('string')
		})

		it('PUTs an existing object to its own url', async () => {
			const fetch = answerWith({ id: 'a' })
			await store.saveObject({ '@self': { id: 'a' } }, { register: 1, schema: 2 })
			expect(fetch.mock.calls[0][0]).toBe(`${OBJECTS}/1/2/a`)
			expect(fetch.mock.calls[0][1].method).toBe('PUT')
		})

		it('rethrows a failed save', async () => {
			answerWith({}, false)
			await expect(store.saveObject({ '@self': { id: 'a' } }, { register: 1, schema: 2 }))
				.rejects.toThrow('Failed to save object: 500 Server Error')
		})
	})

	describe('preloadCollections', () => {
		it('registers types from configuration, then by slug or title, and fetches each collection', async () => {
			jest.spyOn(store, '_ensureInnerType').mockImplementation(() => {})
			const fetchCollection = jest.spyOn(store, 'fetchCollection')
				.mockImplementation((type) => (type === 'menu' ? Promise.reject(new Error('x')) : Promise.resolve()))
			jest.spyOn(console, 'warn').mockImplementation(() => {})
			store.settings = {
				objectTypes: ['page', 'menu', 'glossary'],
				configuration: { page_register: 3, page_schema: 4 },
				availableRegisters: [
					{ id: 7, schemas: [{ id: 8, slug: 'menu' }] },
					{ id: 9, schemas: [{ id: 10, slug: 'other', title: 'Publication' }] },
					{ id: 11 },
				],
			}
			store.objectTypeRegistry = { glossary: { schema: '1', register: '1' } }

			await store.preloadCollections()

			expect(store.objectTypeRegistry).toEqual({
				glossary: { schema: '1', register: '1' },
				page: { schema: '4', register: '3' },
				menu: { schema: '8', register: '7' },
				publication: { schema: '10', register: '9' },
			})
			expect(fetchCollection.mock.calls.map((c) => c[0])).toEqual(['page', 'menu', 'glossary'])
			expect(console.warn).toHaveBeenCalledTimes(1)
		})

		it('loads the settings first when it has none, and logs a failing preload', async () => {
			const fetchSettings = jest.spyOn(store, 'fetchSettings').mockRejectedValue(new Error('down'))
			await store.preloadCollections()
			expect(fetchSettings).toHaveBeenCalled()
			expect(console.error).toHaveBeenCalledWith('Error during preload:', expect.any(Error))
			store._registerTypesFromSettings()
			expect(store.objectTypeRegistry).toEqual({})
		})
	})
})
