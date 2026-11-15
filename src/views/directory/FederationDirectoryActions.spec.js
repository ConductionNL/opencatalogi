/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the Directory page's counts, status messages, paging and
 * modal handling in `FederationDirectory.vue`: the summary counts each
 * listing once under its status, a listing that is not answering says why,
 * a page or page-size change reloads from the right page, and closing one of
 * the page's own modals clears what it was editing and reloads.
 */

import FederationDirectory from './FederationDirectory.vue'
import { navigationStore as mockNavigation } from '../../store/store.js'

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
jest.mock('../../store/store.js', () => ({ navigationStore: { modal: null, setModal: jest.fn() }, objectStore: {} }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({ isAdmin: true }) }))
jest.mock('../../modals/directory/FederationAddDirectoryModal.vue', () => ({}))
jest.mock('../../modals/directory/FederationDeleteListingModal.vue', () => ({}))
jest.mock('../../modals/directory/FederationEditListingModal.vue', () => ({}))

/**
 * A plain `this` with every method bound to it and `load` replaced by a spy.
 *
 * @param {object} state - The page state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { ...FederationDirectory.data(), load: jest.fn(), ...state }
	for (const [name, fn] of Object.entries(FederationDirectory.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	mockNavigation.modal = null
})

describe('FederationDirectory set-up', () => {
	it('starts empty on the first page of 20 and loads on mount', () => {
		expect(FederationDirectory.data()).toEqual({
			syncingId: null,
			listings: [],
			loading: false,
			error: null,
			editingListing: null,
			deletingListing: null,
			page: 1,
			pages: 1,
			total: 0,
			limit: 20,
		})

		const ctx = vm()
		FederationDirectory.mounted.call(ctx)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('takes the administrator flag from the composable', () => {
		expect(FederationDirectory.setup()).toEqual({ isAdmin: true })
	})
})

describe('FederationDirectory summary', () => {
	it('counts every listing once under its status', () => {
		const ctx = vm({
			listings: [
				{ statusCode: 200 },
				{ statusCode: 204 },
				{ statusCode: 404 },
				{ statusCode: 503 },
				{ available: false, statusCode: 200 },
				{},
			],
		})

		expect(FederationDirectory.computed.summary.call(ctx)).toEqual({ up: 2, degraded: 1, down: 2, unknown: 1 })
	})

	it('counts nothing for an empty directory', () => {
		expect(FederationDirectory.computed.summary.call(vm())).toEqual({ up: 0, degraded: 0, down: 0, unknown: 0 })
	})
})

describe('FederationDirectory status words', () => {
	it.each([
		[{ statusCode: 200 }, 'available'],
		[{ statusCode: 302 }, 'degraded'],
		[{ statusCode: 502 }, 'unreachable'],
		[{ available: false }, 'unreachable'],
		[{}, 'unknown'],
	])('labels %j as %s', (listing, label) => {
		expect(vm().statusLabelFor(listing)).toBe(label)
	})

	it.each([
		[{ available: false, statusCode: 500 }, 'Peer is unreachable'],
		[{ statusCode: 404 }, 'HTTP status: 404'],
		[{ statusCode: 199 }, 'HTTP status: 199'],
		[{ statusCode: 300 }, 'HTTP status: 300'],
		[{ statusCode: 200 }, ''],
		[{ statusCode: 299 }, ''],
		[{ statusCode: '500' }, ''],
		[{}, ''],
	])('explains %j as %j', (listing, message) => {
		expect(vm().messageFor(listing)).toBe(message)
	})

	it('reads the sync status from the listing timestamps', () => {
		expect(vm().syncStatusFor({ lastSync: '2026-01-02T00:00:00Z', lastSuccessAt: '2026-01-01T00:00:00Z', lastError: 'timeout' })).toEqual({
			lastSuccessAt: '2026-01-01T00:00:00Z',
			lastAttemptAt: '2026-01-02T00:00:00Z',
			error: 'timeout',
			neverSucceeded: false,
		})
	})
})

describe('FederationDirectory paging', () => {
	it('loads the page asked for and keeps the size', () => {
		const ctx = vm({ page: 1, limit: 50 })

		ctx.onPageChange(4)

		expect(ctx.page).toBe(4)
		expect(ctx.limit).toBe(50)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('goes back to the first page when the page size changes', () => {
		const ctx = vm({ page: 7, limit: 20 })

		ctx.onPageSizeChange(100)

		expect(ctx.page).toBe(1)
		expect(ctx.limit).toBe(100)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})
})

describe('FederationDirectory modals', () => {
	it('opens the add modal', () => {
		vm().openAdd()

		expect(mockNavigation.setModal).toHaveBeenCalledWith('federationAddDirectory')
	})

	it('opens the edit and delete modals on the listing they are for', () => {
		const ctx = vm()
		const listing = { id: 'l1' }

		ctx.openEdit(listing)
		expect(ctx.editingListing).toBe(listing)
		expect(mockNavigation.setModal).toHaveBeenLastCalledWith('federationEditListing')

		ctx.openDelete(listing)
		expect(ctx.deletingListing).toBe(listing)
		expect(mockNavigation.setModal).toHaveBeenLastCalledWith('federationDeleteListing')
	})

	it('follows the navigation store modal', () => {
		mockNavigation.modal = 'federationEditListing'

		expect(FederationDirectory.computed.modalState.call(vm())).toBe('federationEditListing')
	})

	it.each([
		['federationEditListing', { editingListing: null, deletingListing: 'kept' }],
		['federationDeleteListing', { editingListing: 'kept', deletingListing: null }],
		['federationAddDirectory', { editingListing: 'kept', deletingListing: 'kept' }],
	])('closing %s clears its listing and reloads', (closed, after) => {
		const ctx = vm({ editingListing: 'kept', deletingListing: 'kept' })

		FederationDirectory.watch.modalState.call(ctx, null, closed)

		expect({ editingListing: ctx.editingListing, deletingListing: ctx.deletingListing }).toEqual(after)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it.each([
		['another page modal closing', null, 'someOtherModal'],
		['a modal opening', 'federationAddDirectory', null],
		['the same modal staying open', 'federationEditListing', 'federationEditListing'],
	])('does not reload on %s', (label, next, prev) => {
		const ctx = vm({ editingListing: 'kept' })

		FederationDirectory.watch.modalState.call(ctx, next, prev)

		expect(ctx.editingListing).toBe('kept')
		expect(ctx.load).not.toHaveBeenCalled()
	})
})
