/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the upload logic of `UploadFiles.vue`:
 * addAttachments (all pending files or one), retryAllFailed, saveTags (an
 * uploaded file or a pending one), applySchemaDefaults and
 * getRegisterSchemaIds. Each method runs against a plain `this`, so no
 * component is mounted.
 */

import UploadFiles from './UploadFiles.vue'

const mockEvents = []
const mockDispatched = []

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-request-token' }))
jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	dispatchObjectsChanged: (change) => mockDispatched.push(change),
}))

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => '/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))

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
	navigationStore: {},
	objectStore: {
		getActiveObject: jest.fn(),
		setCollection: jest.fn(),
	},
}))

const { catalogStore, objectStore } = jest.requireMock('../../store/store.js')

const PUBLICATION = { id: 'p1', '@self': { register: { id: 7 }, schema: '9' } }

let log

/**
 * A plain `this` with the given state and every method of the component.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { share: false, files: { value: [] }, loading: false, retryLoading: false, error: null, success: null, ...state }
	for (const [name, fn] of Object.entries(UploadFiles.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	ctx.getAllTags = jest.fn(async () => log.push('getAllTags'))
	ctx.getTooBigFiles = (size) => size >= 100
	return ctx
}

beforeEach(() => {
	log = []
	mockEvents.length = 0
	mockDispatched.length = 0
	objectStore.getActiveObject.mockReset().mockReturnValue(PUBLICATION)
	objectStore.setCollection.mockReset().mockImplementation((name, value) => log.push(['setCollection', name, value]))
	catalogStore.fetchPublications.mockReset().mockImplementation(() => log.push('fetchPublications'))
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
	delete global.fetch
})

/**
 * An upload stub answering per file name.
 *
 * @param {object} answers - Per file name: a response, or an Error to reject with
 * @return {Function} The stub
 */
function uploader(answers) {
	return jest.fn((files, reset, share) => {
		const file = files[0]
		log.push(['upload', file.name, file.status, typeof reset, share])
		const answer = answers[file.name]
		return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer)
	})
}

describe('UploadFiles getRegisterSchemaIds', () => {
	it.each([
		[{ register: { id: 1, uuid: 'u' }, schema: { uuid: 's' } }, { registerId: 1, schemaId: 's' }],
		[{ register: '2', schema: 3 }, { registerId: '2', schemaId: 3 }],
		[{ register: null, schema: {} }, { registerId: undefined, schemaId: undefined }],
	])('reads %j', (self, ids) => {
		expect(vm().getRegisterSchemaIds({ '@self': self })).toEqual(ids)
	})
})

describe('UploadFiles addAttachments', () => {
	it('uploads the pending files, refreshes and reports success', async () => {
		const files = [
			{ name: 'a', status: 'pending', size: 1 },
			{ name: 'b', status: 'uploaded', size: 1 },
			{ name: 'c', status: 'failed', size: 1 },
			{ name: 'd', size: 1 },
			{ name: 'big', status: 'pending', size: 100 },
			{ name: 'e', status: 'uploading', size: 1 },
		]
		const ctx = vm({ files: { value: files }, share: true })
		ctx.createPublicationAttachment = uploader({
			a: { status: 200, data: [{ id: 'fa' }] },
			d: { status: 500 },
		})
		global.fetch = jest.fn(async (url) => {
			log.push(['fetch', url])
			return { json: async () => ({ results: ['x'] }) }
		})
		const result = await ctx.addAttachments()
		expect(result).toBeUndefined()
		expect(log).toEqual([
			['upload', 'a', 'uploading', 'function', true],
			['upload', 'd', 'uploading', 'function', true],
			'getAllTags',
			['fetch', '/nc/apps/openregister/api/objects/7/9/p1/files'],
			['setCollection', 'publicationAttachments', ['x']],
			'fetchPublications',
		])
		expect(files.map((f) => f.status)).toEqual(['uploaded', 'uploaded', 'failed', 'failed', 'pending', 'uploading'])
		expect(files[0].id).toBe('fa')
		expect(mockDispatched).toEqual([{ register: 7, schema: '9', id: 'p1' }])
		expect(mockEvents).toEqual([['upload-files:uploaded', { publicationId: 'p1', uploadedCount: 2, failedCount: 0 }]])
		expect(ctx.success).toBe(true)
		expect(ctx.uploadedCount).toBe(2)
		expect(ctx.failedCount).toBe(2)
		expect(ctx.loading).toBe(false)
	})

	it('returns the created ids of one file and keeps a rejected upload as the error', async () => {
		const file = { name: 'one', title: 'One', status: 'pending', size: 1 }
		const ctx = vm({ files: { value: [file] } })
		ctx.createPublicationAttachment = uploader({ one: { status: 200, data: [{ id: 'f1' }] } })
		global.fetch = jest.fn(async () => ({ json: async () => ({}) }))
		expect(await ctx.addAttachments(file)).toEqual({ one: 'f1' })

		const bad = { name: 'bad', status: 'failed', size: 1 }
		ctx.createPublicationAttachment = uploader({ bad: Object.assign(new Error('x'), { response: { data: { error: 'too big' } } }) })
		expect(await ctx.addAttachments(bad)).toEqual({})
		expect(ctx.error).toBe('too big')
		expect(bad.status).toBe('failed')
		expect(mockDispatched).toEqual([{ register: 7, schema: '9', id: 'p1' }])
		expect(mockEvents[1]).toEqual(['upload-files:uploaded', { publicationId: 'p1', uploadedCount: 0, failedCount: 1 }])
	})

	it('falls back to the text of a rejection without an error body', async () => {
		const file = { name: 'z', status: 'pending', size: 1 }
		const ctx = vm({ files: { value: [file] } })
		ctx.createPublicationAttachment = uploader({ z: new Error('plain') })
		global.fetch = jest.fn(async () => ({ json: async () => ({}) }))
		await ctx.addAttachments(file)
		expect(ctx.error).toBe('Error: plain')
	})

	it('ignores an answer without a created id', async () => {
		const file = { name: 'n', status: 'pending', size: 1 }
		const ctx = vm({ files: { value: [file] } })
		ctx.createPublicationAttachment = uploader({ n: { status: 200, data: 'nope' } })
		global.fetch = jest.fn(async () => ({ json: async () => ({}) }))
		expect(await ctx.addAttachments(file)).toEqual({})
		expect(file.id).toBeUndefined()
	})

	it('stops without anything to upload', async () => {
		const ctx = vm({ files: { value: [{ name: 'b', status: 'uploaded' }] } })
		ctx.createPublicationAttachment = jest.fn()
		expect(await ctx.addAttachments()).toBeUndefined()
		expect(ctx.createPublicationAttachment).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
	})

	it('records a failing refresh as the error', async () => {
		const file = { name: 'a', status: 'pending', size: 1 }
		const ctx = vm({ files: { value: [file] }, tagsLoading: true })
		ctx.createPublicationAttachment = uploader({ a: { status: 200, data: [] } })
		const failure = Object.assign(new Error('down'), { response: { data: { error: 'refresh failed' } } })
		global.fetch = jest.fn(async () => {
			throw failure
		})
		await ctx.addAttachments()
		expect(ctx.error).toBe('refresh failed')
		expect(ctx.loading).toBe(false)
		expect(ctx.tagsLoading).toBe(false)
		expect(mockEvents).toEqual([])
	})
})

describe('UploadFiles retryAllFailed', () => {
	it('retries the failed files, refreshes once one succeeds, and reports the counts', async () => {
		const files = [
			{ name: 'a', status: 'failed' },
			{ name: 'b', status: 'uploaded' },
			{ name: 'c', status: 'failed' },
			{ name: 'd', status: 'failed' },
		]
		const ctx = vm({ files: { value: files } })
		ctx.createPublicationAttachment = uploader({ a: { status: 200, data: [{ id: 'ignored' }] }, c: { status: 404 }, d: new Error('x') })
		global.fetch = jest.fn(async (url) => {
			log.push(['fetch', url])
			return { json: async () => ({ results: ['y'] }) }
		})
		await ctx.retryAllFailed()
		expect(log).toEqual([
			['upload', 'a', 'uploading', 'function', false],
			['upload', 'c', 'uploading', 'function', false],
			['upload', 'd', 'uploading', 'function', false],
			['fetch', '/nc/apps/openregister/api/objects/7/9/p1/files'],
			['setCollection', 'publicationAttachments', ['y']],
		])
		expect(files.map((f) => f.status)).toEqual(['uploaded', 'uploaded', 'failed', 'failed'])
		expect(files[0].id).toBeUndefined()
		expect(mockDispatched).toEqual([{ register: 7, schema: '9', id: 'p1' }])
		expect(mockEvents).toEqual([['upload-files:uploaded', { publicationId: 'p1', uploadedCount: 2, failedCount: 1 }]])
		expect(ctx.failedCount).toBe(2)
		expect(ctx.retryLoading).toBe(false)
	})

	it('skips the refresh when nothing succeeds and swallows a refresh failure', async () => {
		const ctx = vm({ files: { value: [{ name: 'a', status: 'failed' }] } })
		ctx.createPublicationAttachment = uploader({ a: new Error('x') })
		global.fetch = jest.fn()
		await ctx.retryAllFailed()
		expect(global.fetch).not.toHaveBeenCalled()
		expect(mockEvents).toEqual([['upload-files:uploaded', { publicationId: 'p1', uploadedCount: 0, failedCount: 1 }]])

		const again = vm({ files: { value: [{ name: 'a', status: 'failed' }] } })
		again.createPublicationAttachment = uploader({ a: { status: 200 } })
		global.fetch = jest.fn(async () => ({ json: async () => {
			throw new Error('bad json')
		} }))
		await again.retryAllFailed()
		expect(log).toContainEqual(['setCollection', 'publicationAttachments', []])
		objectStore.setCollection.mockImplementation(() => {
			throw new Error('store')
		})
		const third = vm({ files: { value: [{ name: 'a', status: 'failed' }] } })
		third.createPublicationAttachment = uploader({ a: { status: 200 } })
		await expect(third.retryAllFailed()).resolves.toBeUndefined()
		expect(third.retryLoading).toBe(false)
	})

	it('reports nothing without an active publication', async () => {
		objectStore.getActiveObject.mockReturnValue(null)
		const ctx = vm({ files: { value: [{ name: 'a', status: 'failed' }] } })
		ctx.createPublicationAttachment = uploader({ a: { status: 200 } })
		await ctx.retryAllFailed()
		expect(mockEvents).toEqual([])
		expect(ctx.uploadedCount).toBe(1)
	})
})

describe('UploadFiles saveTags', () => {
	it('stores the labels of an uploaded file and refreshes the attachments', async () => {
		jest.useFakeTimers()
		const file = { id: 'f1', tags: ['old'] }
		const ctx = vm({ editingTags: 'f1', editedTags: ['x'] })
		global.fetch = jest.fn(async (url, init) => {
			log.push(['fetch', url, init])
			return { ok: true, json: async () => ({ results: ['r'] }) }
		})
		await ctx.saveTags(file, ['a', 'b'])
		expect(log).toEqual([
			['fetch', '/index.php/apps/openregister/api/objects/7/9/p1/files/f1', {
				method: 'PUT',
				headers: { requesttoken: 'test-request-token', 'Content-Type': 'application/json' },
				body: JSON.stringify({ tags: ['a', 'b'] }),
			}],
			['fetch', '/index.php/apps/openregister/api/objects/7/9/p1/files', undefined],
			['setCollection', 'publicationAttachments', ['r']],
			'fetchPublications',
		])
		expect(file.tags).toEqual(['a', 'b'])
		expect(ctx.success).toBe('File labels updated successfully')
		jest.advanceTimersByTime(3000)
		expect(ctx.success).toBe(null)
		expect(ctx.editingTags).toBe(null)
		expect(ctx.editedTags).toEqual([])
	})

	it('keeps going with unreadable attachments and non-array labels', async () => {
		const file = { id: 'f1' }
		const ctx = vm()
		global.fetch = jest.fn(async () => ({ ok: true, json: async () => {
			throw new Error('bad')
		} }))
		await ctx.saveTags(file, 'not-an-array')
		expect(file.tags).toEqual([])
		expect(log).toContainEqual(['setCollection', 'publicationAttachments', []])
	})

	it('reports a refused label update', async () => {
		jest.useFakeTimers()
		const ctx = vm()
		global.fetch = jest.fn(async () => ({ ok: false, statusText: 'Locked' }))
		await ctx.saveTags({ id: 'f1' }, [])
		expect(ctx.error).toBe('Failed to save file labels: Failed to update file tags: Locked')
		expect(console.error).toHaveBeenCalledWith('Error saving tags:', expect.any(Error))
		jest.advanceTimersByTime(5000)
		expect(ctx.error).toBe(null)
	})

	it.each([
		[{ one: 'n1' }, 'n1'],
		[{ One: 'n2' }, 'n2'],
		[{}, undefined],
		[undefined, undefined],
	])('uploads a pending file with its labels (ids %j)', async (idMap, id) => {
		const file = { name: 'one', title: 'One' }
		const ctx = vm()
		ctx.addAttachments = jest.fn(async (f) => {
			log.push(['addAttachments', f.status, f.tags])
			return idMap
		})
		await ctx.saveTags(file, ['t'])
		expect(log).toEqual([['addAttachments', 'pending', ['t']]])
		expect(file.id).toBe(id)
	})
})

describe('UploadFiles applySchemaDefaults', () => {
	it.each([
		[null, undefined, false],
		[{ '@self': {} }, undefined, false],
		[{ '@self': { schema: { configuration: { defaultAutoShare: true } } } }, undefined, true],
		[{ '@self': { schema: { configuration: { defaultAutoShare: 'yes' } } } }, undefined, false],
		[{ '@self': { schema: { id: 4 } } }, { ok: true, json: async () => ({ configuration: { defaultAutoShare: true } }) }, true],
		[{ '@self': { schema: { uuid: 'u' } } }, { ok: true, json: async () => ({}) }, false],
		[{ '@self': { schema: 'slug-x' } }, { ok: false }, false],
		[{ '@self': { schema: {} } }, undefined, false],
		[{ '@self': { schema: 5 } }, { ok: true, json: async () => {
			throw new Error('bad')
		} }, false],
	])('seeds the share toggle for %j', async (publication, answer, share) => {
		objectStore.getActiveObject.mockReturnValue(publication)
		global.fetch = jest.fn(async (url) => {
			log.push(url)
			return answer
		})
		const ctx = vm({ share: true })
		await ctx.applySchemaDefaults()
		expect(ctx.share).toBe(share)
		expect(log.length).toBe(answer ? 1 : 0)
	})

	it('looks schemas up by id, uuid or slug', async () => {
		global.fetch = jest.fn(async (url) => {
			log.push(url)
			return { ok: false }
		})
		for (const schema of [{ id: 1, uuid: 'u' }, { uuid: 'u', slug: 's' }, { slug: 's' }]) {
			objectStore.getActiveObject.mockReturnValue({ '@self': { schema } })
			await vm().applySchemaDefaults()
		}
		expect(log).toEqual([
			'/index.php/apps/openregister/api/schemas/1',
			'/index.php/apps/openregister/api/schemas/u',
			'/index.php/apps/openregister/api/schemas/s',
		])
	})

	it('keeps the share toggle off when the lookup fails', async () => {
		objectStore.getActiveObject.mockReturnValue({ '@self': { schema: 5 } })
		global.fetch = jest.fn(async () => {
			throw new Error('offline')
		})
		const ctx = vm({ share: true })
		await ctx.applySchemaDefaults()
		expect(ctx.share).toBe(false)
	})
})
