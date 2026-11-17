/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the loading and navigation of `CatalogDetailPage.vue`: the
 * catalog is read from the id-keyed cache before the active slot, it is
 * fetched whenever the route id is set, a failed fetch shows its message,
 * and Back, Edit and View Catalog go where they should.
 */

import CatalogDetailPage from './CatalogDetailPage.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({}) }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {
		getObject: jest.fn(),
		getActiveObject: jest.fn(),
		fetchObject: jest.fn(),
		setActiveObject: jest.fn(),
	},
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

const { computed, methods, watch } = CatalogDetailPage

/**
 * A fake instance on route id `id`, with its data and bound methods.
 *
 * @param {object} fields Extra fields (`catalog`, `catalogId`, ...).
 * @return {object}
 */
function vm(fields = {}) {
	const instance = { ...CatalogDetailPage.data(), $router: { push: jest.fn() } }
	for (const [name, fn] of Object.entries(methods)) {
		instance[name] = fn.bind(instance)
	}
	// Fields last, so a test can stand in for a method.
	return Object.assign(instance, fields)
}

describe('CatalogDetailPage catalog', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('takes the id from the route', () => {
		expect(computed.catalogId.call({ $route: { params: { id: '12' } } })).toBe('12')
	})

	it('prefers the cached catalog of the route id', () => {
		objectStore.getObject.mockReturnValue({ id: '12', title: 'Cached' })
		objectStore.getActiveObject.mockReturnValue({ id: '12', title: 'Active' })

		expect(computed.catalog.call({ catalogId: '12' })).toEqual({ id: '12', title: 'Cached' })
		expect(objectStore.getObject).toHaveBeenCalledWith('catalog', '12')
	})

	it('falls back to the active catalog', () => {
		objectStore.getObject.mockReturnValue(undefined)
		objectStore.getActiveObject.mockReturnValue({ id: '12', title: 'Active' })

		expect(computed.catalog.call({ catalogId: '12' })).toEqual({ id: '12', title: 'Active' })
		expect(objectStore.getActiveObject).toHaveBeenCalledWith('catalog')
	})

	it('starts with the four widgets laid out, not loading and with the sidebar open', () => {
		const data = CatalogDetailPage.data()

		expect(data.detailLayout.map((cell) => cell.widgetId)).toEqual(['metadata', 'configuration', 'description', 'raw-data'])
		expect(data).toMatchObject({ loading: false, error: null, sidebarOpen: true })
	})
})

describe('CatalogDetailPage loading', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('loads on a route id and not without one', () => {
		const withId = vm({ catalogId: '12', loadCatalog: jest.fn() })
		const withoutId = vm({ catalogId: undefined, loadCatalog: jest.fn() })

		watch.catalogId.handler.call(withId)
		watch.catalogId.handler.call(withoutId)

		expect(watch.catalogId.immediate).toBe(true)
		expect(withId.loadCatalog).toHaveBeenCalledTimes(1)
		expect(withoutId.loadCatalog).not.toHaveBeenCalled()
	})

	it('fetches the catalog of the route id and stops loading', async () => {
		let during
		const instance = vm({ catalogId: '12', error: 'old' })
		objectStore.fetchObject.mockImplementation(async () => {
			during = instance.loading
		})

		await instance.loadCatalog()

		expect(objectStore.fetchObject).toHaveBeenCalledWith('catalog', '12')
		expect(during).toBe(true)
		expect(instance.loading).toBe(false)
		expect(instance.error).toBeNull()
	})

	it('shows the message of a failed fetch', async () => {
		objectStore.fetchObject.mockRejectedValue(new Error('Catalog not found'))
		const instance = vm({ catalogId: '12' })

		await instance.loadCatalog()

		expect(instance.error).toBe('Catalog not found')
		expect(instance.loading).toBe(false)
	})
})

describe('CatalogDetailPage navigation', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('goes back to the catalog list', () => {
		const instance = vm()
		instance.goBack()

		expect(instance.$router.push).toHaveBeenCalledWith({ name: 'Catalogs' })
	})

	it('edits the shown catalog in the catalog modal', () => {
		const catalog = { id: '12' }
		vm({ catalog }).editCatalog()

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('catalog', catalog)
		expect(navigationStore.setModal).toHaveBeenCalledWith('catalog')
	})

	it('opens the catalog\'s publications by slug', () => {
		const instance = vm({ catalog: { slug: 'woo' } })
		instance.openPublications()

		expect(instance.$router.push).toHaveBeenCalledWith({ name: 'Publications', params: { catalogSlug: 'woo' } })
	})

	it.each([[{ id: '12' }], [null]])('stays put for a catalog without a slug (%j)', (catalog) => {
		const instance = vm({ catalog })
		instance.openPublications()

		expect(instance.$router.push).not.toHaveBeenCalled()
	})
})
