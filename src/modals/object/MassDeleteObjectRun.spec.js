/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `MassDeleteObject.vue`'s deleteObject: the
 * objects it hands to the store, the success and error it shows, the
 * refresh and the delayed close. The method runs against a plain `this`,
 * so no component is mounted.
 */

import MassDeleteObject from './MassDeleteObject.vue'

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: {},
	objectStore: { selectedObjects: [], massDeleteObjects: jest.fn() },
}))

const { catalogStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * A plain `this` for the dialog.
 *
 * @return {object} The context
 */
function vm() {
	const ctx = { loading: false, success: null, error: null, closeModalTimeout: null }
	for (const [name, fn] of Object.entries(MassDeleteObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.closeDialog = jest.fn()
	return ctx
}

beforeEach(() => {
	catalogStore.fetchPublications.mockClear()
	objectStore.massDeleteObjects.mockReset()
	objectStore.selectedObjects = ['a', 'b']
})

afterEach(() => {
	jest.useRealTimers()
})

describe('MassDeleteObject deleteObject', () => {
	it('deletes a copy of the selection, refreshes and closes after two seconds', async () => {
		jest.useFakeTimers()
		objectStore.massDeleteObjects.mockResolvedValue({ successful: [1, 2], failed: [] })
		const ctx = vm()
		await ctx.deleteObject()
		const sent = objectStore.massDeleteObjects.mock.calls[0][0]
		expect(sent).toEqual(['a', 'b'])
		expect(sent).not.toBe(objectStore.selectedObjects)
		expect(ctx.success).toBe(true)
		expect(ctx.error).toBe(null)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		expect(ctx.loading).toBe(false)
		jest.advanceTimersByTime(1999)
		expect(ctx.closeDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(ctx.closeDialog).toHaveBeenCalledTimes(1)
	})

	it.each([
		[{ successful: [1], failed: [2] }, true, 'Failed to delete 1 object', 1],
		[{ successful: [], failed: [2, 3] }, null, 'Failed to delete 2 objects', 0],
		[{ successful: [], failed: [] }, null, null, 0],
	])('reports %j', async (result, success, error, refreshes) => {
		jest.useFakeTimers()
		objectStore.massDeleteObjects.mockResolvedValue(result)
		const ctx = vm()
		await ctx.deleteObject()
		expect(ctx.success).toBe(success)
		expect(ctx.error).toBe(error)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(refreshes)
		jest.runAllTimers()
		expect(ctx.closeDialog).not.toHaveBeenCalled()
	})

	it('handles an empty selection and a failing store', async () => {
		objectStore.selectedObjects = null
		objectStore.massDeleteObjects.mockRejectedValue(new Error('denied'))
		const ctx = vm()
		await ctx.deleteObject()
		expect(objectStore.massDeleteObjects).toHaveBeenCalledWith([])
		expect(ctx.success).toBe(false)
		expect(ctx.error).toBe('denied')
		objectStore.massDeleteObjects.mockRejectedValue({})
		await ctx.deleteObject()
		expect(ctx.error).toBe('An error occurred while deleting objects')
		expect(ctx.loading).toBe(false)
	})
})
