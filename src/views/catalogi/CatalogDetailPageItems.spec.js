/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the configuration rows and widget definitions of
 * `CatalogDetailPage.vue`.
 */

import CatalogDetailPage from './CatalogDetailPage.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({}) }))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {} }))

/**
 * Evaluate the computed `name` on `catalog`.
 *
 * @param {string} name The computed property.
 * @param {object|null} catalog The catalog shown.
 * @return {unknown} Its value.
 */
function computed(name, catalog) {
	return CatalogDetailPage.computed[name].call({ catalog })
}

describe('CatalogDetailPage configItems', () => {
	it('has no rows without a catalog', () => {
		expect(computed('configItems', null)).toEqual([])
	})

	it('counts registers and schemas and answers the two features', () => {
		expect(computed('configItems', { registers: [1, 2], schemas: [3], hasWooSitemap: true, hasOoapi: false })).toEqual([
			{ label: 'Registers', value: 2 },
			{ label: 'Schemas', value: 1 },
			{ label: 'WOO Sitemap', value: 'Yes' },
			{ label: 'OOAPI 5.0 Publication', value: 'No' },
		])
	})

	it('counts missing lists as zero and missing flags as No', () => {
		expect(computed('configItems', { hasOoapi: 1 })).toEqual([
			{ label: 'Registers', value: 0 },
			{ label: 'Schemas', value: 0 },
			{ label: 'WOO Sitemap', value: 'No' },
			{ label: 'OOAPI 5.0 Publication', value: 'Yes' },
		])
	})
})

describe('CatalogDetailPage widgetDefs', () => {
	it('lists the four custom widgets in order', () => {
		expect(computed('widgetDefs', null)).toEqual([
			{ id: 'metadata', title: 'Metadata', type: 'custom' },
			{ id: 'configuration', title: 'Configuration', type: 'custom' },
			{ id: 'description', title: 'Description', type: 'custom' },
			{ id: 'raw-data', title: 'Data', type: 'custom' },
		])
	})
})
