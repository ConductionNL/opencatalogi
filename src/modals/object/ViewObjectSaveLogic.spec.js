/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the save and file actions of `ViewObject.vue`:
 * initializeData (new and existing objects), saveObject (create, update,
 * refusals and failures), deleteSelectedFiles and runFileAction. Each method
 * runs against a plain `this`, so no component is mounted.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

const mockDispatched = []

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	dispatchObjectsChanged: (change) => mockDispatched.push(change),
}))

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => '/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: {},
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		selectedAttachments: [],
		getCollection: jest.fn(),
		setActiveObject: jest.fn(),
	},
}))

const { catalogStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * A plain `this` with the given state and every method of the component.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { formData: {}, currentObject: null, isNewObject: false, $route: { params: {} }, ...state }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

let log

beforeEach(() => {
	log = []
	mockDispatched.length = 0
	catalogStore.fetchPublications.mockReset().mockImplementation(() => log.push('fetchPublications'))
	objectStore.setActiveObject.mockReset().mockImplementation((type, obj) => log.push(['setActiveObject', type, obj]))
	objectStore.availableSchemas = [{ id: 9, title: 'S' }]
	objectStore.selectedAttachments = []
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
	delete global.fetch
})

describe('ViewObject initializeData for a new object', () => {
	/**
	 * A context for a new object with the given catalogs and options.
	 *
	 * @param {object} setup - The catalogs, route slug and option lists
	 * @return {object} The context
	 */
	function newCtx({ catalogs, slug, registers = [], schemas = [] }) {
		objectStore.getCollection.mockReturnValue({ results: catalogs })
		const ctx = vm({
			formData: { stale: 1 },
			$route: { params: slug ? { catalogSlug: slug } : {} },
			registerOptions: registers,
			schemaOptions: schemas,
			selectedCatalog: null,
			selectedRegister: null,
			selectedSchema: null,
		})
		ctx.$nextTick = jest.fn(async () => log.push('tick'))
		return ctx
	}

	it('selects the catalog of the route slug and the single register and schema', async () => {
		const ctx = newCtx({
			catalogs: [{ id: 1, slug: 'a', title: 'A' }, { id: 2, slug: 'b', title: 'B' }],
			slug: 'b',
			registers: [{ id: 'r' }],
			schemas: [{ id: 's' }],
		})
		await ctx.initializeData()
		expect(ctx.formData).toEqual({})
		expect(ctx.selectedCatalog).toEqual({ id: 2, label: 'B' })
		expect(ctx.selectedRegister).toEqual({ id: 'r' })
		expect(ctx.selectedSchema).toEqual({ id: 's' })
		expect(log).toEqual(['tick', 'tick'])
	})

	it('leaves the catalog open for an unknown slug and skips several registers', async () => {
		const ctx = newCtx({ catalogs: [{ id: 1, slug: 'a', title: 'A' }], slug: 'zz', registers: [1, 2] })
		await ctx.initializeData()
		expect(ctx.selectedCatalog).toBe(null)
		expect(ctx.selectedRegister).toBe(null)
		expect(log).toEqual(['tick'])
	})

	it('selects the only catalog without a slug, and no schema among several', async () => {
		const ctx = newCtx({ catalogs: [{ id: 1, slug: 'a', title: 'A' }], registers: [{ id: 'r' }], schemas: [1, 2] })
		await ctx.initializeData()
		expect(ctx.selectedCatalog).toEqual({ id: 1, label: 'A' })
		expect(ctx.selectedRegister).toEqual({ id: 'r' })
		expect(ctx.selectedSchema).toBe(null)
	})

	it('selects nothing among several catalogs without a slug', async () => {
		const ctx = newCtx({ catalogs: [{ id: 1 }, { id: 2 }] })
		await ctx.initializeData()
		expect(ctx.selectedCatalog).toBe(null)
	})
})

describe('ViewObject initializeData for an existing object', () => {
	it('copies the editable values and turns theme objects into ids', async () => {
		const nested = { deep: [1, 2] }
		const ctx = vm({
			currentObject: { id: 'o1', '@self': { id: 'o1' }, title: 't', nested, gone: undefined, themes: [{ id: 'th1' }, 'th2'] },
		})
		await ctx.initializeData()
		expect(ctx.formData).toEqual({ title: 't', nested: { deep: [1, 2] }, themes: ['th1', 'th2'] })
		expect(ctx.formData.nested).not.toBe(nested)
		expect(Object.keys(ctx.formData)).toEqual(['title', 'nested', 'themes'])
	})

	it('keeps a value that cannot be serialised', async () => {
		const cyclic = { a: 1 }
		cyclic.self = cyclic
		const ctx = vm({ currentObject: { cyclic, themes: 'not-an-array' } })
		await ctx.initializeData()
		expect(ctx.formData.cyclic).toBe(cyclic)
		expect(ctx.formData.themes).toBe('not-an-array')
	})
})

describe('ViewObject saveObject', () => {
	/**
	 * A fetch that records its call and answers with the given response.
	 *
	 * @param {object} response - The response
	 * @return {Function} The fetch
	 */
	function answer(response) {
		return jest.fn(async (url, init) => {
			log.push(['fetch', url, init])
			return response
		})
	}

	const saved = { id: 'n1', '@self': { id: 'n1', register: 7, schema: '9' } }
	const ok = { ok: true, json: async () => saved }

	it('refuses while the form cannot be saved', async () => {
		const ctx = vm({ canSave: false, isSaving: false, showRequiredFieldError: false })
		await ctx.saveObject()
		expect(ctx.showRequiredFieldError).toBe(true)
		expect(ctx.isSaving).toBe(false)
	})

	it.each([
		[{ selectedSchema: null, selectedRegister: { id: 1 }, selectedCatalog: { id: 1 } }],
		[{ selectedSchema: { id: 1 }, selectedRegister: null, selectedCatalog: { id: 1 } }],
		[{ selectedSchema: { id: 1 }, selectedRegister: { id: 1 }, selectedCatalog: null }],
	])('does nothing for a new object without all selections (%j)', async (selections) => {
		global.fetch = answer(ok)
		const ctx = vm({ canSave: true, isNewObject: true, ...selections })
		ctx.buildCompleteObjectData = jest.fn()
		await ctx.saveObject()
		expect(ctx.buildCompleteObjectData).not.toHaveBeenCalled()
		expect(global.fetch).not.toHaveBeenCalled()
		expect(ctx.isSaving).toBe(false)
	})

	it('creates a new object and keeps the modal open', async () => {
		jest.useFakeTimers()
		global.fetch = answer(ok)
		const ctx = vm({
			canSave: true,
			isNewObject: true,
			formData: { a: 1 },
			selectedSchema: { id: 9 },
			selectedRegister: { id: 7 },
			selectedCatalog: { id: 1 },
		})
		ctx.buildCompleteObjectData = () => ({ a: 1 })
		ctx.closeModal = jest.fn()
		await ctx.saveObject()
		jest.runAllTimers()
		jest.useRealTimers()
		expect(log).toEqual([
			['fetch', '/index.php/apps/openregister/api/objects/7/9', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ a: 1 }),
			}],
			['setActiveObject', 'publication', { ...saved, '@self': { ...saved['@self'], schema: { id: 9, title: 'S' } } }],
			'fetchPublications',
		])
		expect(mockDispatched).toEqual([{ register: 7, schema: '9', id: 'n1' }])
		expect(ctx.formData).toEqual({})
		expect(ctx.closeModal).not.toHaveBeenCalled()
		expect(ctx.isSaving).toBe(false)
	})

	it('updates an existing object and closes the modal a second later', async () => {
		jest.useFakeTimers()
		global.fetch = answer(ok)
		const ctx = vm({ canSave: true, isNewObject: false, currentObject: { id: 'x', '@self': { id: 'o1' } } })
		ctx.buildCompleteObjectData = () => ({ b: 2 })
		ctx.getRegisterSchemaIds = () => ({ registerId: 3, schemaId: 4 })
		ctx.closeModal = jest.fn()
		await ctx.saveObject()
		expect(log[0][1]).toBe('/index.php/apps/openregister/api/objects/3/4/o1')
		expect(log[0][2].method).toBe('PUT')
		jest.advanceTimersByTime(999)
		expect(ctx.closeModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(ctx.closeModal).toHaveBeenCalledTimes(1)
		jest.useRealTimers()
	})

	it('uses the object id when @self has none', async () => {
		global.fetch = answer(ok)
		const ctx = vm({ canSave: true, currentObject: { id: 'x', '@self': {} } })
		ctx.buildCompleteObjectData = () => ({})
		ctx.getRegisterSchemaIds = () => ({ registerId: 3, schemaId: 4 })
		ctx.closeModal = jest.fn()
		await ctx.saveObject()
		expect(log[0][1]).toBe('/index.php/apps/openregister/api/objects/3/4/x')
	})

	it.each([
		[true, 'create'],
		[false, 'update'],
	])('logs a failed answer (creating: %s)', async (isNewObject, verb) => {
		global.fetch = answer({ ok: false, status: 500, statusText: 'Boom', text: async () => 'details' })
		const ctx = vm({
			canSave: true,
			isNewObject,
			currentObject: { '@self': { id: 'o1' } },
			selectedSchema: { id: 9 },
			selectedRegister: { id: 7 },
			selectedCatalog: { id: 1 },
		})
		ctx.buildCompleteObjectData = () => ({})
		ctx.getRegisterSchemaIds = () => ({ registerId: 3, schemaId: 4 })
		await ctx.saveObject()
		expect(console.error).toHaveBeenCalledWith('Save error:', expect.any(Error))
		expect(console.error.mock.calls[0][1].message).toBe(`Failed to ${verb} publication: 500 Boom - details`)
		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
		expect(ctx.isSaving).toBe(false)
	})
})

describe('ViewObject file actions', () => {
	/**
	 * A context with an object and its files.
	 *
	 * @return {object} The context
	 */
	function filesCtx() {
		const ctx = vm({
			currentObject: { id: 'o1' },
			paginatedFiles: [{ id: 1, title: 'one' }, { id: 2, name: 'two' }, { id: 3 }],
			fileIdsLoading: [],
			publishLoading: [],
			depublishLoading: [],
		})
		ctx.getRegisterSchemaIds = () => ({ registerId: 'r', schemaId: 's' })
		ctx.refreshFiles = jest.fn(async () => log.push('refreshFiles'))
		return ctx
	}

	it('deletes nothing without a selection', async () => {
		global.fetch = jest.fn()
		await filesCtx().deleteSelectedFiles()
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it('deletes the selected files, refreshes and clears the selection', async () => {
		objectStore.selectedAttachments = [2, 1]
		global.fetch = jest.fn(async (url, init) => {
			log.push(['fetch', url, init])
			return { ok: true }
		})
		const ctx = filesCtx()
		await ctx.deleteSelectedFiles()
		expect(log).toEqual([
			['fetch', '/nc/apps/openregister/api/objects/r/s/o1/files/1', { method: 'DELETE' }],
			['fetch', '/nc/apps/openregister/api/objects/r/s/o1/files/2', { method: 'DELETE' }],
			'refreshFiles',
			'fetchPublications',
		])
		expect(objectStore.selectedAttachments).toEqual([])
		expect(ctx.fileIdsLoading).toEqual([])
		expect(mockDispatched).toEqual([{ register: 'r', schema: 's', id: 'o1' }])
	})

	it('stops at a failed delete and still reports the partial change', async () => {
		objectStore.selectedAttachments = [1, 2]
		let calls = 0
		global.fetch = jest.fn(async () => {
			calls++
			return calls === 1 ? { ok: true } : { ok: false, statusText: 'Gone' }
		})
		const ctx = filesCtx()
		await ctx.deleteSelectedFiles()
		expect(console.error).toHaveBeenCalledWith('Failed to delete selected files:', expect.any(Error))
		expect(console.error.mock.calls[0][1].message).toBe('Failed to delete file two: Gone')
		expect(objectStore.selectedAttachments).toEqual([1, 2])
		expect(ctx.refreshFiles).not.toHaveBeenCalled()
		expect(mockDispatched).toEqual([{ register: 'r', schema: 's', id: 'o1' }])
	})

	it('reports no change when the first delete fails', async () => {
		objectStore.selectedAttachments = [3]
		global.fetch = jest.fn(async () => ({ ok: false, statusText: 'No' }))
		await filesCtx().deleteSelectedFiles()
		expect(console.error.mock.calls[0][1].message).toBe('Failed to delete file undefined: No')
		expect(mockDispatched).toEqual([])
	})

	it.each([
		['publish', 'publishLoading', '/nc/apps/openregister/api/objects/r/s/o1/files/2/publish', 'POST'],
		['depublish', 'depublishLoading', '/nc/apps/openregister/api/objects/r/s/o1/files/2/depublish', 'POST'],
		['delete', 'fileIdsLoading', '/nc/apps/openregister/api/objects/r/s/o1/files/2', 'DELETE'],
	])('runs the %s action', async (action, list, url, method) => {
		const ctx = filesCtx()
		ctx[list] = [5]
		global.fetch = jest.fn(async (u, init) => {
			log.push(['fetch', u, init, [...ctx[list]]])
			return { ok: true }
		})
		await ctx.runFileAction({ id: 2 }, action)
		expect(log).toEqual([['fetch', url, { method }, [5, 2]], 'refreshFiles', 'fetchPublications'])
		expect(ctx[list]).toEqual([5])
		expect(mockDispatched).toEqual(action === 'delete' ? [{ register: 'r', schema: 's', id: 'o1' }] : [])
	})

	it('logs a failed file action', async () => {
		const ctx = filesCtx()
		global.fetch = jest.fn(async () => ({ ok: false, statusText: 'Nope' }))
		await ctx.runFileAction({ id: 2 }, 'publish')
		expect(console.error).toHaveBeenCalledWith('Failed to publish file:', expect.any(Error))
		expect(console.error.mock.calls[0][1].message).toBe('Failed to publish file: Nope')
		expect(ctx.refreshFiles).not.toHaveBeenCalled()
		expect(ctx.publishLoading).toEqual([])
	})
})
