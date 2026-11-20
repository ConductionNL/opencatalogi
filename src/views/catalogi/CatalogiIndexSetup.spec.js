/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `CatalogiIndex.vue`'s setup: it exposes exactly the list view
 * bindings it names, the object store and the admin check, in that order.
 */

import CatalogiIndex from './CatalogiIndex.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), inject: jest.fn(() => 'sidebar') }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({ isAdmin: 'admin', loaded: 'loaded' }) }))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: { id: 'store' } }))
jest.mock('@conduction/nextcloud-vue', () => ({
	useListView: jest.fn((type, options) => ({
		schema: 'schema',
		sortKey: 'sortKey',
		sortOrder: 'sortOrder',
		visibleColumns: 'visibleColumns',
		onSort: 'onSort',
		onPageChange: 'onPageChange',
		onPageSizeChange: 'onPageSizeChange',
		refresh: 'refresh',
		extra: 'not exposed',
		seen: { type, options },
	})),
}))

describe('CatalogiIndex setup', () => {
	it('exposes the named list view bindings, the store and the admin check', () => {
		const bindings = CatalogiIndex.setup()

		expect(Object.keys(bindings)).toEqual([
			'schema',
			'sortKey',
			'sortOrder',
			'visibleColumns',
			'onSort',
			'onPageChange',
			'onPageSizeChange',
			'refresh',
			'objectStore',
			'isAdmin',
			'loaded',
		])
		expect(bindings.schema).toBe('schema')
		expect(bindings.refresh).toBe('refresh')
		expect(bindings.objectStore).toEqual({ id: 'store' })
		expect(bindings.isAdmin).toBe('admin')
		expect(bindings.loaded).toBe('loaded')
	})

	it('builds the list view for catalogs with the sidebar state and the store', () => {
		const { useListView } = jest.requireMock('@conduction/nextcloud-vue')
		CatalogiIndex.setup()

		expect(useListView).toHaveBeenLastCalledWith('catalog', { sidebarState: 'sidebar', objectStore: { id: 'store' } })
	})
})
