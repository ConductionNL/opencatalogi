/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the publication type switch watcher of `DirectorySideBar.vue`:
 * a switch turned on copies that publication type, a switch turned off
 * deletes it while publication types are loaded, and right after a listing
 * switch nothing happens.
 */

import DirectorySideBar from './DirectorySideBar.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { getCollection: jest.fn() },
}))

const handler = DirectorySideBar.watch.checkedPublicationType.handler

/**
 * Run the watcher on `change` with `typesLoaded` publication types loaded.
 *
 * @param {{from: object, to: object, switched?: boolean}} change The old and new switches.
 * @param {number} typesLoaded How many publication types the store holds.
 * @return {object} The component state afterwards.
 */
function run(change, typesLoaded) {
	objectStore.getCollection.mockReturnValue({ results: new Array(typesLoaded).fill({}) })
	const state = {
		...DirectorySideBar.methods,
		switchedListing: change.switched ?? false,
		copyPublicationType: jest.fn(),
		deletePublicationType: jest.fn(),
	}
	handler.call(state, change.to, change.from)
	return state
}

describe('DirectorySideBar publication type switches', () => {
	it('copies a publication type whose switch turned on', () => {
		const state = run({ from: { a: false, b: false }, to: { a: false, b: true } }, 1)

		expect(state.copyPublicationType.mock.calls).toEqual([['b']])
		expect(state.deletePublicationType).not.toHaveBeenCalled()
	})

	it('deletes a publication type whose switch turned off while types are loaded', () => {
		const state = run({ from: { a: true, b: true }, to: { a: false, b: true } }, 2)

		expect(state.deletePublicationType.mock.calls).toEqual([['a']])
		expect(state.copyPublicationType).not.toHaveBeenCalled()
		expect(objectStore.getCollection).toHaveBeenCalledWith('publication_type')
	})

	it('deletes nothing when no publication types are loaded', () => {
		const state = run({ from: { a: true }, to: { a: false } }, 0)

		expect(state.deletePublicationType).not.toHaveBeenCalled()
	})

	it('deletes nothing for an empty key', () => {
		const state = run({ from: { '': true }, to: { '': false } }, 1)

		expect(state.deletePublicationType).not.toHaveBeenCalled()
	})

	it('ignores a value that is neither on nor off', () => {
		const state = run({ from: { a: true }, to: { a: 'maybe' } }, 1)

		expect(state.copyPublicationType).not.toHaveBeenCalled()
		expect(state.deletePublicationType).not.toHaveBeenCalled()
	})

	it('does nothing right after a listing switch and clears the flag', () => {
		const state = run({ from: { a: false }, to: { a: true }, switched: true }, 1)

		expect(state.copyPublicationType).not.toHaveBeenCalled()
		expect(state.switchedListing).toBe(false)
	})

	it('does nothing when there were no switches before', () => {
		const state = run({ from: {}, to: { a: true } }, 1)

		expect(state.copyPublicationType).not.toHaveBeenCalled()
	})
})
