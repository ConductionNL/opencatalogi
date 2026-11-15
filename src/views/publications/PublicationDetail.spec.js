/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the logic of `PublicationDetail.vue`: attachment paging,
 * selection, tag editing, route syncing and the publish / depublish / delete
 * actions, run against a mocked store and a mocked `fetch`.
 */

import PublicationDetail from './PublicationDetail.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
jest.mock('../../services/usageStats.js', () => ({ fetchPublicationStats: jest.fn() }))
jest.mock('../../store/store.js', () => {
	const state = { active: {}, collections: {}, objects: {} }
	return {
		__state: state,
		catalogStore: {
			getPublicationAttachments: jest.fn(() => Promise.resolve()),
			fetchPublications: jest.fn(),
		},
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
		objectStore: {
			getActiveObject: jest.fn((type) => state.active[type] ?? null),
			setActiveObject: jest.fn((type, object) => {
				state.active[type] = object
			}),
			getCollection: jest.fn((type) => state.collections[type] ?? { results: [] }),
			getObject: jest.fn((type, id) => state.objects[id] ?? null),
			fetchObject: jest.fn(() => Promise.resolve()),
		},
	}
})

const { __state: store } = jest.requireMock('../../store/store.js')
const { computed, methods } = PublicationDetail

const PUBLICATION = { id: 'pub-1', '@self': { register: 'r1', schema: 's1' }, themes: ['t1', 't9'], data: { a: 1, b: 2 } }

/**
 * Wait for queued promise callbacks to run.
 *
 * @return {Promise<void>}
 */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * A component-like state: data(), the methods, and the computed values as getters.
 *
 * @param {object} overrides Data to replace.
 * @return {object} The state.
 */
function makeVm(overrides = {}) {
	const vm = { ...PublicationDetail.data(), ...overrides }
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(computed)) {
		if (!(name in overrides)) {
			Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
		}
	}
	return vm
}

/**
 * Put attachments in the store's attachment collection.
 *
 * @param {Array<object>} attachments The attachments.
 * @return {void}
 */
function setAttachments(attachments) {
	store.collections.publicationAttachments = { results: attachments }
}

beforeEach(() => {
	jest.clearAllMocks()
	store.active = { publication: PUBLICATION }
	store.collections = {}
	store.objects = {}
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve(['x']) }))
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
	delete global.fetch
})

describe('attachment paging', () => {
	it('reads the attachment array from the collection envelope', () => {
		setAttachments([{ id: 1 }, { id: 2 }])
		const vm = makeVm()

		expect(vm.publicationAttachments).toEqual([{ id: 1 }, { id: 2 }])
		expect(objectStore.getCollection).toHaveBeenCalledWith('publicationAttachments')
		expect(vm.attachmentsTotal).toBe(2)
	})

	it('falls back to an empty list when the envelope has no results', () => {
		store.collections.publicationAttachments = {}

		expect(makeVm().publicationAttachments).toEqual([])
	})

	it('counts pages at the page size, with at least one page', () => {
		setAttachments(Array.from({ length: 25 }, (_, i) => ({ id: i })))

		expect(makeVm({ limit: 10 }).attachmentsTotalPages).toBe(3)
		setAttachments([])
		expect(makeVm({ limit: 10 }).attachmentsTotalPages).toBe(1)
	})

	it('slices the current page', () => {
		setAttachments(Array.from({ length: 25 }, (_, i) => ({ id: i })))
		const vm = makeVm({ limit: 10, currentPage: 3 })

		expect(vm.pagedAttachments.map((a) => a.id)).toEqual([20, 21, 22, 23, 24])
	})

	it('turns the page sizes into value/label options', () => {
		expect(makeVm().pageSizeOptions).toEqual([
			{ value: 10, label: '10' },
			{ value: 20, label: '20' },
			{ value: 50, label: '50' },
			{ value: 100, label: '100' },
			{ value: 200, label: '200' },
		])
	})

	it('clamps a page change to the available pages', () => {
		setAttachments(Array.from({ length: 25 }, (_, i) => ({ id: i })))
		const vm = makeVm({ limit: 10 })

		vm.onAttachmentsPageChanged(9)
		expect(vm.currentPage).toBe(3)
		vm.onAttachmentsPageChanged(0)
		expect(vm.currentPage).toBe(1)
		vm.onAttachmentsPageChanged(2)
		expect(vm.currentPage).toBe(2)
	})

	it('stores a new page size as a number and returns to page one', () => {
		const vm = makeVm({ currentPage: 4 })

		vm.onAttachmentsPageSizeChanged('50')

		expect(vm.limit).toBe(50)
		expect(vm.currentPage).toBe(1)
	})
})

describe('publication and themes', () => {
	it('reads ids from the active publication', () => {
		const vm = makeVm()

		expect(vm.publication).toBe(PUBLICATION)
		expect([vm.registerId, vm.schemaId, vm.publicationId]).toEqual(['r1', 's1', 'pub-1'])
	})

	it('splits the publication themes into known and missing ones', () => {
		store.collections.theme = { results: [{ id: 't1' }, { id: 't2' }] }
		const vm = makeVm()

		expect(vm.filteredThemes).toEqual([{ id: 't1' }])
		expect(vm.missingThemes).toEqual(['t9'])
	})

	it('reports whether every known theme is selected', () => {
		store.collections.theme = { results: [{ id: 't1' }, { id: 't9' }] }
		const vm = makeVm({ selectedThemes: ['t1'] })

		expect(vm.allThemesSelected()).toBe(false)
		vm.selectedThemes = ['t1', 't9']
		expect(vm.allThemesSelected()).toBe(true)
	})

	it('select-all reads allThemesSelected as a method, which is always truthy, so it clears the selection', () => {
		store.collections.theme = { results: [{ id: 't1' }, { id: 't9' }] }
		const vm = makeVm({ selectedThemes: ['t1'] })

		vm.selectAllThemes()

		expect(vm.selectedThemes).toEqual([])
	})

	it('does nothing with theme selection when there are no themes', () => {
		const vm = makeVm({ selectedThemes: ['keep'] })

		vm.selectAllThemes()

		expect(vm.selectedThemes).toEqual(['keep'])
		expect(vm.allThemesSelected()).toBe(false)
	})

	it('toggles one theme in and out of the selection', () => {
		const vm = makeVm()

		vm.toggleThemeSelection({ id: 't1' })
		expect(vm.selectedThemes).toEqual(['t1'])
		vm.toggleThemeSelection({ id: 't1' })
		expect(vm.selectedThemes).toEqual([])
	})

	it('opens the bulk theme delete dialog only with a selection', () => {
		const vm = makeVm()

		vm.bulkDeleteThemes()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()

		vm.selectedThemes = ['t1']
		vm.bulkDeleteThemes()
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteMultipleThemes')
	})

	it('closes the dialog and clears the selection when the theme delete is done', () => {
		const vm = makeVm({ selectedThemes: ['t1'] })

		vm.onBulkDeleteThemesDone()

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(vm.selectedThemes).toEqual([])
	})

	it('keeps the selection when the theme delete is cancelled', () => {
		const vm = makeVm({ selectedThemes: ['t1'] })

		vm.onBulkDeleteThemesCancel()

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(vm.selectedThemes).toEqual(['t1'])
	})
})

describe('publication data selection', () => {
	it('reports whether every data key is selected', () => {
		const vm = makeVm({ selectedPublicationData: ['a'] })

		expect(vm.allPublicationDataSelected()).toBe(false)
		vm.selectedPublicationData = ['a', 'b']
		expect(vm.allPublicationDataSelected()).toBe(true)
	})

	it('select-all reads allPublicationDataSelected as a method, which is always truthy, so it clears the selection', () => {
		const vm = makeVm({ selectedPublicationData: ['a'] })

		vm.selectAllPublicationData()

		expect(vm.selectedPublicationData).toEqual([])
	})

	it('reports nothing selected for a publication without data', () => {
		store.active.publication = { ...PUBLICATION, data: undefined }
		const vm = makeVm({ selectedPublicationData: ['keep'] })

		vm.selectAllPublicationData()

		expect(vm.selectedPublicationData).toEqual(['keep'])
		expect(vm.allPublicationDataSelected()).toBe(false)
	})

	it('toggles one data key', () => {
		const vm = makeVm()

		vm.togglePublicationDataSelection('a')
		expect(vm.selectedPublicationData).toEqual(['a'])
		vm.togglePublicationDataSelection('a')
		expect(vm.selectedPublicationData).toEqual([])
	})

	it('opens the bulk data delete dialog only with a selection', () => {
		const vm = makeVm()

		vm.bulkDeleteEigenschappen()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()

		vm.selectedPublicationData = ['a']
		vm.bulkDeleteEigenschappen()
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteMultiplePublicationData')
	})
})

describe('attachment selection', () => {
	const attachments = [
		{ id: 1, published: '2026-01-01' },
		{ id: 2, published: null },
		{ id: 3, published: '2026-02-01' },
	]

	it('toggles one attachment', () => {
		const vm = makeVm()

		vm.toggleSelection({ id: 1 })
		vm.toggleSelection({ id: 2 })
		expect(vm.selectedAttachments).toEqual([1, 2])
		vm.toggleSelection({ id: 1 })
		expect(vm.selectedAttachments).toEqual([2])
	})

	it('counts selected published and unpublished attachments, ignoring unknown ids', () => {
		setAttachments(attachments)
		const vm = makeVm({ selectedAttachments: [1, 2, 3, 99] })

		expect(vm.selectedPublishedCount()).toBe(2)
		expect(vm.selectedUnpublishedCount()).toBe(1)
		expect(vm.selectedAttachmentsEntities().map((a) => a.id)).toEqual([1, 2, 3])
	})

	it('knows when every published attachment is selected', () => {
		setAttachments(attachments)

		expect(makeVm({ selectedAttachments: [1] }).allPublishedSelected).toBe(false)
		expect(makeVm({ selectedAttachments: [1, 3] }).allPublishedSelected).toBe(true)
	})

	it('knows when every unpublished attachment is selected', () => {
		setAttachments(attachments)

		expect(makeVm({ selectedAttachments: [1] }).allUnpublishedSelected).toBe(false)
		expect(makeVm({ selectedAttachments: [2] }).allUnpublishedSelected).toBe(true)
	})

	it('reports neither group as selected when it is empty', () => {
		setAttachments([])
		const vm = makeVm({ selectedAttachments: [1] })

		expect(vm.allPublishedSelected).toBe(false)
		expect(vm.allUnpublishedSelected).toBe(false)
	})
})

describe('small helpers', () => {
	it.each([
		[512, '512 bytes'],
		[2048, '2.00 KB'],
		[3 * 1024 * 1024, '3.00 MB'],
		[1.5 * 1024 * 1024 * 1024, '1.50 GB'],
	])('formats %s as %s', (size, text) => {
		expect(methods.formatFileSize(size)).toBe(text)
	})

	it('opens links and folders in a window', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		const vm = makeVm()

		vm.openLink('https://a', '_self')
		vm.openLink('https://b')
		vm.openFolder('/folder')

		expect(open.mock.calls).toEqual([['https://a', '_self'], ['https://b', ''], ['/folder', '_blank']])
		open.mockRestore()
	})

	it('opens the upload modal to add an attachment', () => {
		makeVm().addAttachment()

		expect(navigationStore.setModal).toHaveBeenCalledWith('uploadFiles')
	})

	it('marks an attachment for deletion and opens the dialog', () => {
		makeVm().deleteFile({ id: 5 })

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publicationAttachment', { id: 5 })
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteAttachment')
	})

	it('toggles the active attachment by value', () => {
		const vm = makeVm()

		vm.setActiveAttachment({ id: 5 })
		expect(store.active.publicationAttachment).toEqual({ id: 5 })
		vm.setActiveAttachment({ id: 5 })
		expect(store.active.publicationAttachment).toBe(false)
	})
})

describe('tags', () => {
	it('starts editing an attachment\'s tags', () => {
		const vm = makeVm()

		vm.editTags({ title: 'File', labels: ['x'] })

		expect(vm.editingTags).toBe('File')
		expect(vm.editedTags).toEqual(['x'])
	})

	it('adds a new tag to the options and to the edited tags once', () => {
		const vm = makeVm({ editedTags: null })

		vm.addNewTag('')
		expect(vm.labelOptionsEdit.options).toBeUndefined()

		vm.addNewTag('new')
		vm.addNewTag('new')

		expect(vm.labelOptionsEdit.options).toEqual(['new'])
		expect(vm.editedTags).toEqual(['new'])
	})

	it('keeps existing options when adding a tag', () => {
		const vm = makeVm({ editedTags: ['old'] })
		vm.labelOptionsEdit.options = ['old']

		vm.addNewTag('new')

		expect(vm.labelOptionsEdit.options).toEqual(['old', 'new'])
		expect(vm.editedTags).toEqual(['old', 'new'])
	})

	it('fetches the tag list', async () => {
		const result = await makeVm().getTags()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags', { method: 'get' })
		expect(result.data).toEqual(['x'])
	})

	it('saves the edited tags, then resets editing and reloads', async () => {
		const vm = makeVm({ editedTags: ['a', 'b'], editingTags: 'File', currentPage: 2, limit: 10 })

		await vm.saveTags({ id: 'f1' })
		await flush()

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r1/s1/pub-1/files/f1',
			{
				method: 'PUT',
				body: JSON.stringify({ tags: ['a', 'b'] }),
				headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
			},
		)
		expect(vm.editingTags).toBeNull()
		expect(vm.editedTags).toEqual([])
		expect(vm.labelOptionsEdit.options).toEqual(['x'])
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledWith({ page: 2, limit: 10 })
		expect(vm.saveTagsLoading).toEqual([])
		expect(vm.fileIdsLoading).toEqual([])
	})

	it('keeps editing when saving the tags fails', async () => {
		global.fetch = jest.fn((url, options) => (options.method === 'PUT'
			? Promise.reject(new Error('down'))
			: Promise.resolve({ json: () => Promise.resolve([]) })))
		const vm = makeVm({ editedTags: ['a'], editingTags: 'File' })

		await vm.saveTags({ id: 'f1' })
		await flush()

		expect(console.error).toHaveBeenCalled()
		expect(vm.editingTags).toBe('File')
		expect(vm.saveTagsLoading).toEqual([])
	})
})

describe('route syncing', () => {
	it('loads nothing without a route id', async () => {
		const vm = makeVm()
		vm.$route = { params: {} }

		await vm.syncWithRoute()

		expect(objectStore.fetchObject).not.toHaveBeenCalled()
		expect(catalogStore.getPublicationAttachments).not.toHaveBeenCalled()
	})

	it('skips the fetch for the publication already active, then loads its attachments', async () => {
		const vm = makeVm()
		vm.$route = { params: { id: 'pub-1' } }

		await vm.syncWithRoute()

		expect(objectStore.fetchObject).not.toHaveBeenCalled()
		expect(vm.previousPublicationId).toBe('pub-1')
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledTimes(1)
	})

	it('fetches another publication and makes it active', async () => {
		store.objects['pub-2'] = { id: 'pub-2' }
		const vm = makeVm()

		await vm.ensurePublicationLoadedFromRoute('pub-2')

		expect(objectStore.fetchObject).toHaveBeenCalledWith('publication', 'pub-2', { _extend: '@self.schema,@self.register' })
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', { id: 'pub-2' })
		expect(vm.previousPublicationId).toBe('pub-2')
	})

	it('leaves the active publication when the fetched one is not found', async () => {
		const vm = makeVm()

		await vm.ensurePublicationLoadedFromRoute('pub-3')

		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
		expect(vm.previousPublicationId).toBeNull()
	})

	it('logs a failed fetch', async () => {
		objectStore.fetchObject.mockRejectedValueOnce(new Error('down'))

		await makeVm().ensurePublicationLoadedFromRoute('pub-3')

		expect(console.error).toHaveBeenCalledWith('Failed to load publication from route:', expect.any(Error))
	})

	it('resyncs only when the route id really changes', () => {
		const vm = makeVm()
		vm.syncWithRoute = jest.fn()

		vm.onRouteIdChange('a', 'a')
		vm.onRouteIdChange(undefined, 'a')
		expect(vm.syncWithRoute).not.toHaveBeenCalled()

		vm.onRouteIdChange('b', 'a')
		expect(vm.syncWithRoute).toHaveBeenCalledTimes(1)
	})

	it('reloads attachments after an update only when the active publication changed', () => {
		const vm = makeVm({ previousPublicationId: 'pub-1' })

		PublicationDetail.updated.call(vm)
		expect(catalogStore.getPublicationAttachments).not.toHaveBeenCalled()

		store.active.publication = { ...PUBLICATION, id: 'pub-2' }
		PublicationDetail.updated.call(vm)
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledTimes(1)
		expect(vm.previousPublicationId).toBe('pub-2')
	})
})

describe('publishing', () => {
	it('publishes the publication and makes the response active', async () => {
		global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ '@self': { id: 'pub-1' }, title: 'T' }) }))

		makeVm().publishPublication('publish')
		await flush()

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r1/s1/pub-1/publish',
			{ method: 'POST', headers: { requesttoken: 'token-1' } },
		)
		expect(catalogStore.fetchPublications).toHaveBeenCalled()
		expect(store.active.publication).toEqual({ '@self': { id: 'pub-1' }, title: 'T', id: 'pub-1' })
	})

	it('bulk publishes only the selected unpublished attachments', async () => {
		setAttachments([
			{ id: 1, published: null },
			{ id: 2, published: '2026-01-01' },
			{ id: 3, published: null },
		])
		const vm = makeVm({ selectedAttachments: [1, 2], currentPage: 2, limit: 10 })
		vm.publishFile = jest.fn(() => Promise.resolve())

		vm.bulkPublish()
		await flush()

		expect(vm.publishFile).toHaveBeenCalledTimes(1)
		expect(vm.publishFile).toHaveBeenCalledWith({ id: 1, published: null })
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledWith('pub-1', { page: 2, limit: 10 })
		expect(vm.selectedAttachments).toEqual([])
	})

	it('runs a bulk action over every attachment and drops the publication loading mark', async () => {
		const vm = makeVm({ selectedAttachments: [1], publishLoading: ['pub-1'] })
		const act = jest.fn(() => Promise.resolve())

		vm.runBulkFileAction([{ id: 1 }, { id: 2 }], { loadingList: 'publishLoading', act })
		expect(vm.publishLoading).toEqual(['pub-1', 1, 2])
		await flush()

		expect(act).toHaveBeenCalledTimes(2)
		expect(vm.publishLoading).toEqual([1, 2])
		expect(vm.selectedAttachments).toEqual([])
	})

	it('bulk depublish reads the attachment list as an envelope, so it acts on nothing', async () => {
		setAttachments([{ id: 1, published: '2026-01-01' }])
		const vm = makeVm({ selectedAttachments: [1] })
		vm.depublishFile = jest.fn(() => Promise.resolve())

		vm.bulkDepublish()
		await flush()

		expect(vm.depublishFile).not.toHaveBeenCalled()
		expect(vm.selectedAttachments).toEqual([])
	})
})
