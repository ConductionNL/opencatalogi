/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the actions of `DownloadObject.vue` and `LockObject.vue`, and
 * the registration status line of `WooIndexConnection.vue`.
 */

import WooIndexConnection from '../../views/settings/WooIndexConnection.vue'
import DownloadObject from './DownloadObject.vue'
import LockObject from './LockObject.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { objectItem: { id: 'o1' }, downloadObject: jest.fn(), lockObject: jest.fn() },
}))

beforeEach(() => {
	jest.useFakeTimers()
	objectStore.downloadObject.mockReset()
	objectStore.lockObject.mockReset()
})

afterEach(() => {
	jest.useRealTimers()
})

/**
 * A modal state with `component`'s methods and a close spy.
 *
 * @param {object} component The modal.
 * @param {object} fields State to set.
 * @return {object} The state.
 */
function modal(component, fields = {}) {
	return { ...component.methods, closeModal: jest.fn(), loading: false, ...fields }
}

describe('DownloadObject downloadObject', () => {
	it('closes two seconds after a good download', async () => {
		objectStore.downloadObject.mockResolvedValue({ ok: true })
		const state = modal(DownloadObject)
		await DownloadObject.methods.downloadObject.call(state)

		expect(objectStore.downloadObject).toHaveBeenCalledWith({ id: 'o1' })
		expect(state).toMatchObject({ success: true, error: false, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).toHaveBeenCalledTimes(1)
	})

	it('stays open after a refused download', async () => {
		objectStore.downloadObject.mockResolvedValue({ ok: false })
		const state = modal(DownloadObject)
		await DownloadObject.methods.downloadObject.call(state)

		expect(state).toMatchObject({ success: false, error: false, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).not.toHaveBeenCalled()
	})

	it.each([
		[new Error('Nope'), 'Nope'],
		[{}, 'An error occurred while downloading the object'],
	])('shows the error of a failed download', async (err, expected) => {
		objectStore.downloadObject.mockRejectedValue(err)
		const state = modal(DownloadObject)
		await DownloadObject.methods.downloadObject.call(state)

		expect(state).toMatchObject({ success: false, error: expected, loading: false })
	})
})

describe('LockObject lockObject', () => {
	it('locks with process and duration and closes after two seconds', async () => {
		objectStore.lockObject.mockResolvedValue({})
		const state = modal(LockObject, { process: 'review', duration: 600 })
		await LockObject.methods.lockObject.call(state)

		expect(objectStore.lockObject).toHaveBeenCalledWith('o1', 'review', 600)
		expect(state).toMatchObject({ success: true, error: null, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).toHaveBeenCalledTimes(1)
	})

	it('sends no process or duration when they are empty', async () => {
		objectStore.lockObject.mockResolvedValue({})
		const state = modal(LockObject, { process: '', duration: 0 })
		await LockObject.methods.lockObject.call(state)

		expect(objectStore.lockObject).toHaveBeenCalledWith('o1', undefined, undefined)
	})

	it.each([
		[new Error('Locked'), 'Locked'],
		[{}, 'Failed to lock object'],
	])('shows the error of a failed lock', async (err, expected) => {
		objectStore.lockObject.mockRejectedValue(err)
		const state = modal(LockObject, { process: '', duration: 0, success: null })
		await LockObject.methods.lockObject.call(state)

		expect(state).toMatchObject({ success: null, error: expected, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.closeModal).not.toHaveBeenCalled()
	})
})

describe('WooIndexConnection statusLabel', () => {
	const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => String(vars[key]))
	const label = (registration) => WooIndexConnection.computed.statusLabel.call({ ...WooIndexConnection.methods, t, registration })

	it.each([
		[{ status: 'requested', registeredAt: 'D' }, 'Requested on D. Waiting for the Woo-index to confirm.'],
		[{ status: 'registered', registeredAt: 'D', registeredUrl: 'U' }, 'Registered on D for U.'],
		[{ status: 'none' }, 'Not registered yet.'],
		[{}, 'Not registered yet.'],
	])('describes %j', (registration, expected) => {
		expect(label(registration)).toBe(expected)
	})
})
