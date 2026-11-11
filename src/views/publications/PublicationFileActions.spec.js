/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the file publication actions of
 * `PublicationDetail.vue`: publishFile, depublishFile and their bulk
 * variants — the request, the loading lists, the refreshes and the log on
 * failure. Each method runs against a plain `this`, so no component is
 * mounted.
 */

import PublicationDetail from './PublicationDetail.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('../../store/store.js', () => ({
	catalogStore: { getPublicationAttachments: jest.fn(), fetchPublications: jest.fn() },
	navigationStore: {},
	objectStore: {},
}))

const { catalogStore } = jest.requireMock('../../store/store.js')

let log

/**
 * A plain `this` with every method of the page and the given state.
 *
 * @param {object} state - The page state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = {
		registerId: 'r',
		schemaId: 's',
		publicationId: 'p',
		publishLoading: [],
		depublishLoading: [],
		fileIdsLoading: [],
		selectedAttachments: [],
		currentPage: 2,
		limit: 10,
		publication: { id: 'p' },
		...state,
	}
	for (const [name, fn] of Object.entries(PublicationDetail.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	log = []
	catalogStore.getPublicationAttachments.mockReset().mockImplementation(async (...args) => {
		log.push(['getPublicationAttachments', ...args])
	})
	catalogStore.fetchPublications.mockReset().mockImplementation(() => log.push(['fetchPublications']))
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
	delete global.fetch
})

describe.each([
	['publishFile', 'publish', 'publishLoading', 'Error publishing file:'],
	['depublishFile', 'depublish', 'depublishLoading', 'Error depublishing file:'],
])('PublicationDetail %s', (method, action, list, message) => {
	it('posts the action, then refreshes and clears the loading marks', async () => {
		const ctx = vm({ [list]: [7], fileIdsLoading: [7] })
		global.fetch = jest.fn(async (url, init) => {
			log.push(['fetch', url, init, [...ctx[list]], [...ctx.fileIdsLoading]])
			return { ok: true }
		})
		await ctx[method]({ id: 3 })
		await flush()
		expect(log).toEqual([
			['fetch', `/index.php/apps/openregister/api/objects/r/s/p/files/3/${action}`, { method: 'POST' }, [7, 3], [7, 3]],
			['getPublicationAttachments'],
			['fetchPublications'],
		])
		expect(ctx[list]).toEqual([7])
		expect(ctx.fileIdsLoading).toEqual([7])
	})

	it('logs a failed request and still refreshes', async () => {
		const ctx = vm()
		global.fetch = jest.fn(async () => {
			throw new Error('offline')
		})
		await ctx[method]({ id: 3 })
		await flush()
		expect(console.error).toHaveBeenCalledWith(message, expect.any(Error))
		expect(log).toEqual([['getPublicationAttachments'], ['fetchPublications']])
		expect(ctx[list]).toEqual([])
	})
})

describe('PublicationDetail bulk file actions', () => {
	it('publishes the selected unpublished attachments, then reloads the page and clears the selection', async () => {
		global.fetch = jest.fn(async (url) => {
			log.push(['fetch', url])
			return { ok: true }
		})
		const ctx = vm({
			selectedAttachments: [1, 2, 3],
			publicationAttachments: [{ id: 1, published: false }, { id: 2, published: true }, { id: 3 }, { id: 4 }],
			publishLoading: ['p', 9],
		})
		ctx.bulkPublish()
		await flush()
		await flush()
		expect(log.filter((l) => l[0] === 'fetch').map((l) => l[1])).toEqual([
			'/index.php/apps/openregister/api/objects/r/s/p/files/1/publish',
			'/index.php/apps/openregister/api/objects/r/s/p/files/3/publish',
		])
		expect(log).toContainEqual(['getPublicationAttachments', 'p', { page: 2, limit: 10 }])
		expect(ctx.selectedAttachments).toEqual([])
		// The bulk action and publishFile both mark an attachment as loading,
		// and only publishFile clears its mark, so one mark per attachment stays.
		expect(ctx.publishLoading).toEqual([9, 1, 3])
	})

	it('depublishes the selected published attachments from the results list', async () => {
		global.fetch = jest.fn(async (url) => {
			log.push(['fetch', url])
			return { ok: true }
		})
		const ctx = vm({
			selectedAttachments: [1, 2],
			publicationAttachments: { results: [{ id: 1, published: true }, { id: 2, published: false }, { id: 5, published: true }] },
			depublishLoading: ['p'],
		})
		ctx.bulkDepublish()
		await flush()
		await flush()
		expect(log.filter((l) => l[0] === 'fetch').map((l) => l[1])).toEqual([
			'/index.php/apps/openregister/api/objects/r/s/p/files/1/depublish',
		])
		expect(ctx.selectedAttachments).toEqual([])
		expect(ctx.depublishLoading).toEqual([1])
	})

	it('reloads even with nothing to act on', async () => {
		global.fetch = jest.fn()
		const publish = vm({ publicationAttachments: undefined, selectedAttachments: [1] })
		publish.bulkPublish()
		const depublish = vm({ publicationAttachments: [{ id: 1, published: true }], selectedAttachments: [1] })
		depublish.bulkDepublish()
		await flush()
		expect(global.fetch).not.toHaveBeenCalled()
		expect(log).toEqual([
			['getPublicationAttachments', 'p', { page: 2, limit: 10 }],
			['getPublicationAttachments', 'p', { page: 2, limit: 10 }],
		])
	})
})
