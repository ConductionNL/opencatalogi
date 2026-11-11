/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `AddPublicationThemeModal.vue`'s saveTheme: the
 * publication update, the theme refresh, the success and error states and
 * the delayed close. The method runs against a plain `this`, so no
 * component is mounted.
 */

import AddPublicationThemeModal from './AddPublicationThemeModal.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		getActiveObject: jest.fn(),
		updateObject: jest.fn(),
		fetchCollection: jest.fn(),
	},
}))

const { objectStore } = jest.requireMock('../../store/store.js')

let log

/**
 * A plain `this` with the given state.
 *
 * @param {object} state - The modal state
 * @return {object} The context
 */
function vm(state) {
	const ctx = { isSaving: false, successState: false, errorState: null, closeModal: jest.fn(), ...state }
	for (const [name, fn] of Object.entries(AddPublicationThemeModal.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	log = []
	objectStore.getActiveObject.mockReset().mockReturnValue({ id: 'p1', title: 'P' })
	objectStore.updateObject.mockReset().mockImplementation(async (...args) => log.push(['updateObject', ...args]))
	objectStore.fetchCollection.mockReset().mockImplementation(async (type) => log.push(['fetchCollection', type]))
})

afterEach(() => {
	jest.useRealTimers()
})

describe('AddPublicationThemeModal saveTheme', () => {
	it('sets the theme on the publication, refreshes the themes and closes later', async () => {
		jest.useFakeTimers()
		const ctx = vm({ selectedTheme: 't1' })
		await ctx.saveTheme()
		expect(log).toEqual([
			['updateObject', 'publication', 'p1', { id: 'p1', title: 'P', theme: 't1' }],
			['fetchCollection', 'theme'],
		])
		expect(ctx.successState).toBe(true)
		expect(ctx.errorState).toBe(null)
		expect(ctx.isSaving).toBe(false)
		jest.advanceTimersByTime(1499)
		expect(ctx.closeModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(ctx.closeModal).toHaveBeenCalledTimes(1)
	})

	it('does nothing without a selected theme', async () => {
		const ctx = vm({ selectedTheme: null })
		await ctx.saveTheme()
		expect(objectStore.getActiveObject).not.toHaveBeenCalled()
		expect(ctx.isSaving).toBe(false)
	})

	it('reports a missing publication', async () => {
		objectStore.getActiveObject.mockReturnValue(null)
		const ctx = vm({ selectedTheme: 't1' })
		await ctx.saveTheme()
		expect(ctx.errorState).toBe('No publication selected')
		expect(ctx.isSaving).toBe(false)
		expect(log).toEqual([])
	})

	it('reports a failed update', async () => {
		objectStore.updateObject.mockRejectedValue(new Error('denied'))
		const ctx = vm({ selectedTheme: 't1', successState: true })
		await ctx.saveTheme()
		expect(ctx.errorState).toBe('denied')
		expect(ctx.successState).toBe(false)
		expect(ctx.isSaving).toBe(false)
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
	})
})
