/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `UploadObject.vue`'s uploadObject: the object it saves, the
 * outcome flags, and the close two seconds after a successful save.
 */

import UploadObject from './UploadObject.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { saveObject: jest.fn() },
}))

/**
 * Run uploadObject on a component state built from `overrides`.
 *
 * @param {object} overrides State to change.
 * @return {Promise<object>} The state after the save settles.
 */
async function upload(overrides = {}) {
	const state = {
		...UploadObject.methods,
		object: '{"title":"A"}',
		registers: { value: { id: 3 } },
		schemas: { value: { id: 7 } },
		mappings: { value: { id: 9 } },
		loading: false,
		success: null,
		error: false,
		closeModal: jest.fn(),
		...overrides,
	}
	await UploadObject.methods.uploadObject.call(state)
	// Let the save's then, catch and finally callbacks run.
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
	return state
}

describe('UploadObject uploadObject', () => {
	beforeEach(() => {
		objectStore.saveObject.mockReset()
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('saves the parsed object with register, schema and mapping', async () => {
		objectStore.saveObject.mockResolvedValue({ response: { ok: true } })
		const state = await upload()

		expect(objectStore.saveObject).toHaveBeenCalledWith({
			object: { title: 'A' },
			register: 3,
			schema: 7,
			mapping: 9,
			schemas: '',
		})
		expect(state).toMatchObject({ success: true, error: false, loading: false })
		expect(state.closeModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).toHaveBeenCalledTimes(1)
	})

	it('falls back on missing ids and a missing mapping', async () => {
		objectStore.saveObject.mockResolvedValue({ response: { ok: false } })
		const state = await upload({ object: 'null', registers: { value: {} }, schemas: { value: {} }, mappings: undefined })

		expect(objectStore.saveObject).toHaveBeenCalledWith({
			object: '',
			register: '',
			schema: '',
			mapping: null,
			schemas: '',
		})
		expect(state).toMatchObject({ success: false, error: false, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).not.toHaveBeenCalled()
	})

	it.each([
		[new Error('Nope'), 'Nope'],
		[{}, 'An error occurred while uploading the object'],
	])('shows the error of a failed save', async (err, expected) => {
		objectStore.saveObject.mockRejectedValue(err)
		const state = await upload()

		expect(state).toMatchObject({ success: false, error: expected, loading: false })
	})

	it('rejects on invalid JSON before saving and keeps loading', async () => {
		const state = {
			...UploadObject.methods,
			object: '{',
			registers: { value: { id: 3 } },
			schemas: { value: { id: 7 } },
			loading: false,
		}

		await expect(UploadObject.methods.uploadObject.call(state)).rejects.toThrow(SyntaxError)
		expect(objectStore.saveObject).not.toHaveBeenCalled()
		expect(state.loading).toBe(true)
	})
})
