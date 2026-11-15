/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for `LockObject.vue`: it locks the active object with the given
 * process and duration (left out when empty), shows success and closes two
 * seconds later, reports a failure, and resets the form on closing. The
 * methods run against a plain `this`.
 */

import LockObject from './LockObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { objectItem: { id: 'obj-1' }, lockObject: jest.fn() },
}))

/**
 * A plain `this` with the component's data and methods.
 *
 * @param {object} [overrides] Data overrides.
 * @return {object} The context.
 */
function vm(overrides = {}) {
	const ctx = { ...LockObject.data(), ...overrides }
	for (const [name, fn] of Object.entries(LockObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.clearAllMocks()
	objectStore.lockObject.mockResolvedValue({})
})

afterEach(() => {
	jest.useRealTimers()
})

describe('LockObject', () => {
	it('starts with no process and an hour', () => {
		expect(LockObject.data()).toMatchObject({ process: '', duration: 3600, success: null, loading: false, error: null })
	})

	it('locks the active object with the process and duration, then closes after two seconds', async () => {
		const ctx = vm({ process: 'Review', duration: 60 })
		const pending = ctx.lockObject()
		expect(ctx.loading).toBe(true)
		await pending

		expect(objectStore.lockObject).toHaveBeenCalledWith('obj-1', 'Review', 60)
		expect(ctx).toMatchObject({ success: true, error: null, loading: false })
		jest.advanceTimersByTime(1999)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('leaves out an empty process and duration', async () => {
		await vm({ process: '', duration: '' }).lockObject()
		expect(objectStore.lockObject).toHaveBeenCalledWith('obj-1', undefined, undefined)
	})

	it.each([
		[new Error('Already locked'), 'Already locked'],
		[{}, 'Failed to lock object'],
	])('reports a failed lock and stays open', async (failure, message) => {
		objectStore.lockObject.mockRejectedValue(failure)
		const ctx = vm()
		await ctx.lockObject()
		expect(ctx).toMatchObject({ success: null, error: message, loading: false })
		jest.runAllTimers()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('resets the form and cancels a pending close on closing', async () => {
		const ctx = vm({ process: 'Review', duration: 60 })
		await ctx.lockObject()
		ctx.closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(ctx).toMatchObject({ process: '', duration: 3600, success: null, loading: false, error: null })
		jest.runAllTimers()
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
	})
})
