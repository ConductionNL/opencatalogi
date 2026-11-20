/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the row click and the View row action of `CatalogiIndex.vue`:
 * both open the catalog detail page by id, and warn when the row has no id.
 */

import CatalogiIndex from './CatalogiIndex.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({}) }))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {} }))

/**
 * Call method `name` on `row` with a router mock.
 *
 * @param {string} name The method.
 * @param {object} row The row.
 * @return {object} The router mock.
 */
function call(name, row) {
	const router = { push: jest.fn() }
	CatalogiIndex.methods[name].call({ ...CatalogiIndex.methods, $router: router }, row)
	return router
}

describe('CatalogiIndex catalog navigation', () => {
	beforeEach(() => {
		jest.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		console.warn.mockRestore()
	})

	it.each(['onRowClick', 'viewCatalog'])('%s opens the detail page by id', (name) => {
		const router = call(name, { id: 42 })

		expect(router.push).toHaveBeenCalledWith({ name: 'CatalogDetail', params: { id: '42' } })
		expect(console.warn).not.toHaveBeenCalled()
	})

	it('onRowClick warns about a row without id', () => {
		const row = { title: 'x' }
		const router = call('onRowClick', row)

		expect(router.push).not.toHaveBeenCalled()
		expect(console.warn).toHaveBeenCalledWith('[opencatalogi] onRowClick: no id resolvable from row', row)
	})

	it('viewCatalog warns about a row without id', () => {
		const row = { title: 'x' }
		const router = call('viewCatalog', row)

		expect(router.push).not.toHaveBeenCalled()
		expect(console.warn).toHaveBeenCalledWith('[opencatalogi] viewCatalog: no id resolvable from row', row)
	})
})
