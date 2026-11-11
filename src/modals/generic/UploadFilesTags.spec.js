/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the tag bookkeeping of `UploadFiles.vue`:
 * getAllTags (the label options, the initial, latest and new tags, the
 * shared tags collection and the fetched event) and the close events that
 * closeDialog and onExternalClose send. Each method runs against a plain
 * `this`, so no component is mounted.
 */

import UploadFiles from './UploadFiles.vue'

const mockEvents = []

jest.mock('../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({
		openFileUpload: () => {},
		files: { value: [] },
		reset: () => {},
		setTags: () => {},
		rejectedDuplicates: { value: [] },
	}),
}))

jest.mock('../../eventBus.js', () => ({
	EventBus: { $emit: (...args) => mockEvents.push(args), $on: () => {}, $off: () => {} },
}))

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: { setDialog: jest.fn() },
	objectStore: {
		collections: {},
		getCollection(name) {
			return this.collections[name]
		},
		setCollection: jest.fn(function(name, value) {
			this.collections[name] = value
		}),
		getActiveObject: () => ({ id: 'p1' }),
		setActiveObject: jest.fn(),
	},
}))

const { catalogStore, navigationStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * A plain `this` with the given state and every method of the component.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = {
		labelOptions: { value: [], options: [] },
		labelOptionsEdit: { options: [] },
		initialTags: [],
		latestTags: [],
		newTags: [],
		tagsLoading: false,
		...state,
	}
	for (const [name, fn] of Object.entries(UploadFiles.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	ctx.resetDialogState = jest.fn()
	return ctx
}

beforeEach(() => {
	mockEvents.length = 0
	objectStore.collections = {}
	objectStore.setCollection.mockClear()
	navigationStore.setDialog.mockClear()
	catalogStore.fetchPublications.mockClear()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	global.t = (app, text) => text
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
	delete global.fetch
})

describe('UploadFiles getAllTags', () => {
	it('builds the label options and tracks the new tags', async () => {
		objectStore.collections = {
			publicationAttachments: { results: [{ labels: ['att'] }] },
			tags: ['stored'],
		}
		global.fetch = jest.fn(async () => ({ json: async () => ['b', 'a'] }))
		const ctx = vm({ initialTags: ['a'] })
		await ctx.getAllTags()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags', { method: 'get' })
		expect(ctx.labelOptions.options).toEqual(['No label', 'a', 'att', 'b', 'stored'])
		expect(ctx.labelOptionsEdit.options).toEqual(['a', 'att', 'b', 'stored'])
		expect(ctx.initialTags).toEqual(['a'])
		expect(ctx.latestTags).toEqual(['a', 'att', 'b', 'stored'])
		expect(ctx.newTags).toEqual(['att', 'b', 'stored'])
		expect(objectStore.collections.tags).toEqual(['a', 'att', 'b', 'stored'])
		expect(mockEvents).toEqual([['upload-files:tags-fetched', { tags: ['a', 'att', 'b', 'stored'], newTags: ['att', 'b', 'stored'] }]])
		expect(ctx.tagsLoading).toBe(false)
	})

	it('takes the first fetch as the initial tags and survives an unreadable answer', async () => {
		global.fetch = jest.fn(async () => ({ json: async () => {
			throw new Error('bad')
		} }))
		const ctx = vm()
		await ctx.getAllTags()
		expect(ctx.initialTags).toEqual([])
		expect(ctx.labelOptions.options).toEqual(['No label'])

		global.fetch = jest.fn(async () => ({ json: async () => ['x'] }))
		await ctx.getAllTags()
		expect(ctx.initialTags).toEqual(['x'])
		expect(ctx.newTags).toEqual([])
	})

	it('ignores a failing tags collection and logs a failed fetch', async () => {
		objectStore.setCollection.mockImplementationOnce(() => {
			throw new Error('store')
		})
		global.fetch = jest.fn(async () => ({ json: async () => ['x'] }))
		const ctx = vm()
		await ctx.getAllTags()
		expect(mockEvents).toHaveLength(1)

		global.fetch = jest.fn(async () => {
			throw new Error('offline')
		})
		await ctx.getAllTags()
		expect(console.error).toHaveBeenCalledWith('Failed to fetch tags', expect.any(Error))
		expect(ctx.tagsLoading).toBe(false)
	})
})

describe('UploadFiles close events', () => {
	it('closeDialog stores the tags, sends both events and closes', () => {
		jest.useFakeTimers()
		objectStore.collections = { tags: ['old'] }
		const ctx = vm({ latestTags: ['a'], newTags: ['a'] })
		ctx.closeDialog()
		expect(objectStore.collections.tags).toEqual(['a'])
		expect(mockEvents).toEqual([
			['upload-files:closed', { tags: ['a'], newTags: ['a'] }],
			['upload-files:tags-updated', { tags: ['a'], newTags: ['a'] }],
		])
		expect(navigationStore.setDialog).toHaveBeenCalledWith(null)
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', { id: 'p1' })
		expect(catalogStore.fetchPublications).toHaveBeenCalled()
		expect(ctx.resetDialogState).toHaveBeenCalled()
		expect(ctx.__uploadFilesClosingInternally).toBe(true)
		jest.runAllTimers()
		expect(ctx.__uploadFilesClosingInternally).toBe(false)
	})

	it('closeDialog without tags sends only the closed event', () => {
		const ctx = vm({ latestTags: [], newTags: undefined })
		ctx.closeDialog()
		expect(mockEvents).toEqual([['upload-files:closed', { tags: [], newTags: undefined }]])
	})

	it('onExternalClose skips an internal close and otherwise sends the events', () => {
		const internal = vm({ __uploadFilesClosingInternally: true })
		internal.onExternalClose()
		expect(mockEvents).toEqual([])
		expect(internal.__uploadFilesClosingInternally).toBe(false)
		expect(internal.resetDialogState).not.toHaveBeenCalled()

		const external = vm({ latestTags: ['z'], newTags: null })
		external.onExternalClose()
		expect(mockEvents).toEqual([
			['upload-files:closed', { tags: ['z'], newTags: [] }],
			['upload-files:tags-updated', { tags: ['z'], newTags: [] }],
		])
		expect(external.resetDialogState).toHaveBeenCalled()

		mockEvents.length = 0
		vm({ latestTags: [] }).onExternalClose()
		expect(mockEvents).toEqual([['upload-files:closed', { tags: [], newTags: [] }]])
	})
})
