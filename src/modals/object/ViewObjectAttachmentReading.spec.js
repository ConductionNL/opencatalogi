/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Text column and "Read again" of the publication file list in
 * `ViewObject.vue` (scanned-attachments-become-searchable REQ-SOCR-004). Each
 * method runs against a plain `this`, so no component is mounted.
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))
// @nextcloud/browser-storage ships ESM only, which this Jest setup does not transform.
jest.mock(
	'@nextcloud/browser-storage',
	() => ({
		getBuilder: () => ({
			persist: () => ({ build: () => new Map() }),
			build: () => new Map(),
		}),
	}),
	{ virtual: true },
)
jest.mock('@conduction/nextcloud-vue', () => ({
	dispatchObjectsChanged: jest.fn(),
	CnMetadataTab: {},
	CnPropertiesTab: {},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))
jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: {},
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		selectedAttachments: [],
		setActiveObject: jest.fn(),
		fetchRelatedData: jest.fn(async () => undefined),
	},
}))

const READINGS_URL = '/nc/apps/opencatalogi/api/publications/p1/attachment-readings'

/**
 * A plain `this` with the given state and the component's methods.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = {
		currentObject: { id: 'p1', '@self': { id: 'p1', register: 3, schema: 9 } },
		isNewObject: false,
		attachmentReadings: {},
		canReadAgain: false,
		getRegisterSchemaIds: () => ({ registerId: 3, schemaId: 9 }),
		...state,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * A fetch double answering per URL and recording each call.
 *
 * @param {Record<string, {status?: number, body: object}>} answers - The answer per URL
 * @return {Array} The calls
 */
function fakeFetch(answers) {
	const calls = []
	global.fetch = jest.fn(async (url, options) => {
		calls.push([url, options])
		const answer = answers[url] ?? { status: 404, body: {} }
		const status = answer.status ?? 200
		return { ok: status < 400, status, json: async () => answer.body }
	})
	return calls
}

afterEach(() => {
	delete global.fetch
	jest.clearAllMocks()
})

describe('ViewObject attachment reading (REQ-SOCR-004)', () => {
	it('starts with no readings and no Read again', () => {
		const data = ViewObject.data.call({})
		expect(data.attachmentReadings).toEqual({})
		expect(data.canReadAgain).toBe(false)
	})

	it('shows "Typed text" for the PDF and "No text: OCR is not installed" for the scan', async () => {
		fakeFetch({
			[READINGS_URL]: {
				body: {
					readings: [
						{ fileId: 11, state: 'typed' },
						{ fileId: 12, state: 'unavailable', reason: 'OCR is not installed' },
					],
					canReadAgain: true,
				},
			},
		})
		const ctx = vm()

		await ctx.loadAttachmentReadings()

		expect(ctx.attachmentText({ id: 11 })).toBe('Typed text')
		expect(ctx.attachmentText({ id: 12 })).toBe('No text: OCR is not installed')
		expect(ctx.attachmentText({ id: 13 })).toBe('')
		expect(ctx.attachmentTextReason({ id: 12 })).toBe('OCR is not installed')
		expect(ctx.canReadAttachmentAgain({ id: 12 })).toBe(true)
		expect(ctx.canReadAttachmentAgain({ id: 13 })).toBe(false)
	})

	it('names the reason of a failed reading in the tooltip', () => {
		const ctx = vm({ attachmentReadings: { 14: { fileId: 14, state: 'failed', reason: 'OCR did not complete' } } })

		expect(ctx.attachmentText({ id: 14 })).toBe('Failed')
		expect(ctx.attachmentTextReason({ id: 14 })).toBe('OCR did not complete')
	})

	it('offers no Read again to a reader without the update right', () => {
		const ctx = vm({ attachmentReadings: { 12: { fileId: 12, state: 'none' } }, canReadAgain: false })

		expect(ctx.canReadAttachmentAgain({ id: 12 })).toBe(false)
	})

	it('reads nothing for a new object', async () => {
		const calls = fakeFetch({})
		const ctx = vm({ isNewObject: true, canReadAgain: true, attachmentReadings: { 1: {} } })

		await ctx.loadAttachmentReadings()

		expect(calls).toEqual([])
		expect(ctx.attachmentReadings).toEqual({})
		expect(ctx.canReadAgain).toBe(false)
	})

	it('reloads the readings with the file list', async () => {
		const { objectStore } = jest.requireMock('../../store/store.js')
		const calls = fakeFetch({ [READINGS_URL]: { body: { readings: [{ fileId: 11, state: 'ocr' }], canReadAgain: false } } })
		const ctx = vm()

		await ctx.refreshFiles({ _page: 1, _limit: 20 })

		expect(objectStore.fetchRelatedData).toHaveBeenCalledWith(expect.objectContaining({ id: 'p1', dataType: 'files', params: { _page: 1, _limit: 20 } }))
		expect(calls.map(([url]) => url)).toEqual([READINGS_URL])
		expect(ctx.attachmentText({ id: 11 })).toBe('Read by OCR')
	})

	it('queues a new reading and shows "Reading" until the job answers', async () => {
		const calls = fakeFetch({ [READINGS_URL + '/12/read-again']: { body: { fileId: 12, queued: true } } })
		const ctx = vm({ attachmentReadings: { 12: { fileId: 12, state: 'failed', reason: 'OCR did not complete' } }, canReadAgain: true })

		await ctx.readAttachmentAgain({ id: 12 })

		expect(calls[0]).toEqual([READINGS_URL + '/12/read-again', { method: 'POST', headers: { Accept: 'application/json', requesttoken: 'tok' } }])
		expect(ctx.attachmentText({ id: 12 })).toBe('Reading')
		expect(ctx.attachmentTextReason({ id: 12 })).toBe('')
		expect(showSuccess).toHaveBeenCalledWith('The attachment will be read again.')
	})

	it('says why a new reading was refused and keeps the state', async () => {
		fakeFetch({ [READINGS_URL + '/12/read-again']: { status: 403, body: { message: 'You may not change this publication.' } } })
		const ctx = vm({ attachmentReadings: { 12: { fileId: 12, state: 'none' } }, canReadAgain: true })

		await ctx.readAttachmentAgain({ id: 12 })

		expect(showError).toHaveBeenCalledWith('You may not change this publication.')
		expect(ctx.attachmentText({ id: 12 })).toBe('No text found')
	})
})
