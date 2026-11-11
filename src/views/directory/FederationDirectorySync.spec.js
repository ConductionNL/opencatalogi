/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the status text and the single-listing sync of
 * `FederationDirectory.vue`.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import FederationDirectory from './FederationDirectory.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
// @nextcloud/dialogs is not installed in this tree; the mock stands in for it.
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }), { virtual: true })
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => String(vars[key])),
	t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => String(vars[key])),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {} }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({}) }))
jest.mock('../../modals/directory/FederationAddDirectoryModal.vue', () => ({}))
jest.mock('../../modals/directory/FederationDeleteListingModal.vue', () => ({}))
jest.mock('../../modals/directory/FederationEditListingModal.vue', () => ({}))

describe('FederationDirectory statusTextFor', () => {
	/**
	 * The status text for a listing with `status`.
	 *
	 * @param {object} status What syncStatusFor returns.
	 * @return {string} The text.
	 */
	function text(status) {
		const state = {
			...FederationDirectory.methods,
			syncStatusFor: () => status,
			statusLabelFor: () => 'Active',
		}
		return FederationDirectory.methods.statusTextFor.call(state, {})
	}

	it('says it never synchronised', () => {
		expect(text({ neverSucceeded: true })).toBe('Active, never synchronised successfully')
	})

	it('names the last successful sync in the viewer locale', () => {
		const at = '2026-01-02T03:04:05Z'
		expect(text({ neverSucceeded: false, lastSuccessAt: at })).toBe(
			'Active, last successful sync ' + new Date(at).toLocaleString(),
		)
	})
})

describe('FederationDirectory syncNow', () => {
	/**
	 * Run syncNow on a state built from `fields`.
	 *
	 * @param {object} fields State to set.
	 * @param {object} listing The listing.
	 * @return {Promise<object>} The state afterwards.
	 */
	async function sync(fields, listing) {
		const state = { ...FederationDirectory.methods, isAdmin: true, syncingId: null, load: jest.fn(), ...fields }
		await FederationDirectory.methods.syncNow.call(state, listing)
		return state
	}

	beforeEach(() => jest.clearAllMocks())

	it.each([
		['not an administrator', { isAdmin: false }],
		['already syncing', { syncingId: 'other' }],
	])('does nothing when %s', async (label, fields) => {
		const state = await sync(fields, { id: 'l1' })

		expect(axios.post).not.toHaveBeenCalled()
		expect(state.load).not.toHaveBeenCalled()
	})

	it('syncs, reports by title and reloads', async () => {
		axios.post.mockResolvedValue({})
		const state = await sync({}, { id: 'l1', title: 'Woo', directory: 'https://d' })

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/listings/sync', { id: 'l1' })
		expect(showSuccess).toHaveBeenCalledWith('Synchronised Woo')
		expect(state.syncingId).toBeNull()
		expect(state.load).toHaveBeenCalledTimes(1)
	})

	it('reports a failure by directory when there is no title, and reloads', async () => {
		axios.post.mockRejectedValue(new Error('down'))
		const state = await sync({}, { id: 'l1', directory: 'https://d' })

		expect(showError).toHaveBeenCalledWith('Could not synchronise https://d')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(state.syncingId).toBeNull()
		expect(state.load).toHaveBeenCalledTimes(1)
	})

	it('marks the listing as syncing while the request runs', async () => {
		let seen
		const state = { ...FederationDirectory.methods, isAdmin: true, syncingId: null, load: jest.fn() }
		axios.post.mockImplementation(async () => {
			seen = state.syncingId
			return {}
		})
		await FederationDirectory.methods.syncNow.call(state, { id: 'l1', title: 'Woo' })

		expect(seen).toBe('l1')
	})
})
