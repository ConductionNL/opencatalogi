/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for FederationSearch's paginationState: the store's pagination,
 * with page 1, 1 page, 0 total and 20 per page for every missing or falsy
 * value.
 */

import FederationSearch from './FederationSearch.vue'

jest.mock('../../store/modules/search.ts', () => ({ useSearchStore: () => ({}) }))
jest.mock('../../store/store.js', () => ({ objectStore: {} }))

/**
 * Evaluate paginationState for `pagination`.
 *
 * @param {object|undefined} pagination The store's pagination.
 * @return {object} The state CnPagination gets.
 */
function state(pagination) {
	return FederationSearch.computed.paginationState.call({ searchStore: { pagination } })
}

describe('FederationSearch paginationState', () => {
	it('falls back on every value without a pagination', () => {
		expect(state(undefined)).toEqual({ page: 1, pages: 1, total: 0, limit: 20 })
	})

	it('passes the store values through, in a fixed key order', () => {
		const out = state({ limit: 50, total: 120, pages: 3, page: 2, offset: 50 })

		expect(out).toEqual({ page: 2, pages: 3, total: 120, limit: 50 })
		expect(Object.keys(out)).toEqual(['page', 'pages', 'total', 'limit'])
	})

	it('treats zero and empty values as missing', () => {
		expect(state({ page: 0, pages: 0, total: 0, limit: '' })).toEqual({ page: 1, pages: 1, total: 0, limit: 20 })
	})
})
