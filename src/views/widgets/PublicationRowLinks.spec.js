/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the catalog-slug lookup behind a publication
 * link, in `UnpublishedPublicationsWidget.vue` (onRowClick) and
 * `DashboardSideBar.vue` (handleViewPublication).
 */

import DashboardSideBar from '../../sidebars/dashboard/DashboardSideBar.vue'
import UnpublishedPublicationsWidget from './UnpublishedPublicationsWidget.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	CnDataTable: { name: 'CnDataTable', render: () => null },
	registerIcons: () => {},
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))
jest.mock('axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({ openFileUpload: () => {}, files: { value: [] }, reset: () => {}, setTags: () => {} }),
}))
jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		collections: {},
		getCollection(name) {
			return this.collections[name]
		},
		setActiveObject: jest.fn(),
	},
}))

const { objectStore } = jest.requireMock('../../store/store.js')

const CATALOGS = [{ id: 1, slug: 'one' }, { id: 'c2', slug: 'two' }, null, { slug: 'no-id' }]

const CASES = [
	[{ id: 'p', catalog: 1 }, 'one'],
	[{ id: 'p', catalog: '1' }, 'one'],
	[{ id: 'p', catalog: { id: 'c2' } }, 'two'],
	[{ id: 'p', catalog: { id: 'x', slug: 'own' } }, 'own'],
	[{ id: 'p', catalog: 'x' }, undefined],
	// Without a catalog id the null entry matches first, so there is no slug.
	[{ id: 'p' }, undefined],
	[{ catalog: 1 }, undefined],
]

describe('UnpublishedPublicationsWidget onRowClick', () => {
	beforeEach(() => {
		objectStore.collections = { catalog: { results: CATALOGS } }
		delete window.location
		window.location = { href: '' }
	})

	it.each(CASES)('links %j to the catalog %s', (publication, slug) => {
		UnpublishedPublicationsWidget.methods.onRowClick({ publication })
		expect(window.location.href).toBe(slug ? `/nc/apps/opencatalogi/publications/${slug}/p` : '')
	})

	it('does nothing without catalogs', () => {
		objectStore.collections = {}
		UnpublishedPublicationsWidget.methods.onRowClick({ publication: { id: 'p', catalog: { slug: 's' } } })
		expect(window.location.href).toBe('/nc/apps/opencatalogi/publications/s/p')
	})
})

describe('DashboardSideBar handleViewPublication', () => {
	/**
	 * Run the handler with a route slug.
	 *
	 * @param {object} publication - The publication
	 * @param {string|undefined} routeSlug - The route's catalog slug
	 * @return {Array} The router pushes
	 */
	function view(publication, routeSlug) {
		const pushes = []
		const ctx = { $router: { push: (path) => pushes.push(path) }, $route: { params: { catalogSlug: routeSlug } } }
		DashboardSideBar.methods.handleViewPublication.call(ctx, publication)
		return pushes
	}

	beforeEach(() => {
		objectStore.collections = { catalogus: { results: CATALOGS } }
		objectStore.setActiveObject.mockClear()
	})

	it.each(CASES)('opens %j in the catalog %s', (publication, slug) => {
		expect(view(publication)).toEqual(slug ? [`/publications/${slug}/p`] : [])
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', publication)
	})

	it('falls back to the route catalog', () => {
		expect(view({ id: 'p', catalog: 'x' }, 'route')).toEqual(['/publications/route/p'])
		expect(view({ id: 'p', catalog: 'x' }, undefined)).toEqual([])
	})
})
