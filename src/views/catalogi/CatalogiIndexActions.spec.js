/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the row actions, columns, listing and store calls of
 * `CatalogiIndex.vue`: which actions an admin sees, what each one tells the
 * object and navigation stores, how the listing reads the store's collection
 * and pagination, and that the refresh spinner always stops.
 */

import CatalogiIndex from './CatalogiIndex.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({}) }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	objectStore: {
		getCollection: jest.fn(),
		getPagination: jest.fn(),
		getObject: jest.fn(),
		setActiveObject: jest.fn(),
		clearActiveObject: jest.fn(),
	},
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

const { computed, methods } = CatalogiIndex

/**
 * A fake instance with every method bound to it.
 *
 * @param {object} fields Extra fields.
 * @return {object}
 */
function vm(fields = {}) {
	const instance = { ...fields, $router: { push: jest.fn() } }
	for (const [name, fn] of Object.entries(methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

describe('CatalogiIndex row actions', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('offers View, Edit, View Catalog, Copy and Delete in that order, Delete destructive', () => {
		const actions = computed.rowActions.call(vm({ isAdmin: true }))

		expect(actions.map((a) => a.label)).toEqual(['View', 'Edit', 'View Catalog', 'Copy', 'Delete'])
		expect(actions.map((a) => a.destructive === true)).toEqual([false, false, false, false, true])
	})

	it.each([[true, ['View', 'Edit', 'View Catalog', 'Copy', 'Delete']], [false, ['View', 'View Catalog']]])(
		'shows an admin (%s) only the actions they may use',
		(isAdmin, labels) => {
			const actions = computed.rowActions.call(vm({ isAdmin }))

			expect(actions.filter((a) => a.visible === undefined || a.visible()).map((a) => a.label)).toEqual(labels)
		},
	)

	it('binds each action to its own method', () => {
		const instance = vm({ isAdmin: true })
		const handlers = computed.rowActions.call(instance).map((a) => a.handler)

		expect(handlers).toEqual([
			instance.viewCatalog,
			instance.editCatalog,
			instance.openCatalog,
			instance.copyCatalog,
			instance.deleteCatalog,
		])
	})

	it('edits a catalog by making it active and opening the catalog modal', () => {
		const catalog = { id: 1 }
		vm().editCatalog(catalog)

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('catalog', catalog)
		expect(navigationStore.setModal).toHaveBeenCalledWith('catalog')
	})

	it.each([['copyCatalog', 'copyObject'], ['deleteCatalog', 'deleteObject']])(
		'%s makes the catalog active and opens the %s dialog',
		(method, dialog) => {
			const catalog = { id: 2 }
			vm()[method](catalog)

			expect(objectStore.setActiveObject).toHaveBeenCalledWith('catalog', catalog)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(dialog, { objectType: 'catalog', dialogTitle: 'Catalogus' })
		},
	)

	it('opens the catalog\'s publications by slug', () => {
		const instance = vm()
		instance.openCatalog({ slug: 'woo' })

		expect(instance.$router.push).toHaveBeenCalledWith('/publications/woo')
	})

	it('adds a catalog by clearing the active one and opening the modal', () => {
		vm().onAdd()

		expect(objectStore.clearActiveObject).toHaveBeenCalledWith('catalog')
		expect(navigationStore.setModal).toHaveBeenCalledWith('catalog')
	})

	it('keeps the selected ids', () => {
		const instance = vm({ selectedIds: [] })
		instance.onSelect(['a', 'b'])

		expect(instance.selectedIds).toEqual(['a', 'b'])
	})
})

describe('CatalogiIndex listing', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('has sortable title and status columns and three plain ones', () => {
		const columns = computed.tableColumns.call(vm())

		expect(columns.map((c) => [c.key, c.sortable === true])).toEqual([
			['title', true],
			['listed', true],
			['registers', false],
			['schemas', false],
			['organization', false],
		])
	})

	it.each([
		[[{ id: 1 }], [{ id: 1 }]],
		[{ results: [{ id: 2 }] }, [{ id: 2 }]],
		[{}, []],
		[undefined, []],
	])('reads the catalog collection %j as %j', (collection, objects) => {
		objectStore.getCollection.mockReturnValue(collection)

		expect(computed.currentObjects.call(vm())).toEqual(objects)
		expect(objectStore.getCollection).toHaveBeenCalledWith('catalog')
	})

	it('uses the store\'s pagination, or one empty page', () => {
		objectStore.getPagination.mockReturnValueOnce({ total: 40, page: 2, pages: 2, limit: 20 })
		expect(computed.currentPagination.call(vm())).toEqual({ total: 40, page: 2, pages: 2, limit: 20 })

		objectStore.getPagination.mockReturnValueOnce(null)
		expect(computed.currentPagination.call(vm())).toEqual({ total: 0, page: 1, pages: 1, limit: 20 })
	})

	it('names the organization, or says it is unknown', () => {
		objectStore.getObject.mockReturnValueOnce({ name: 'Gemeente X' }).mockReturnValueOnce(undefined)
		const instance = vm()

		expect(instance.getOrganizationName('o1')).toBe('Gemeente X')
		expect(instance.getOrganizationName('o2')).toBe('Unknown Organization')
		expect(objectStore.getObject).toHaveBeenCalledWith('organization', 'o1')
	})

	it('spins while refreshing and stops when the refresh fails', async () => {
		let during
		const instance = vm({
			isRefreshing: false,
			refresh: jest.fn(async () => {
				during = instance.isRefreshing
				throw new Error('offline')
			}),
		})

		await expect(instance.handleRefresh()).rejects.toThrow('offline')

		expect(during).toBe(true)
		expect(instance.isRefreshing).toBe(false)
	})
})
