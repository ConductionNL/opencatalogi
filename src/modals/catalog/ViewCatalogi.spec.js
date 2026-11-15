/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the catalogue view dialog `ViewCatalogi.vue`: its tabs, the
 * active catalogue and its organisation, the register and schema lookups, and
 * where each action leads.
 */

import ViewCatalogi from './ViewCatalogi.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	objectStore: {
		getActiveObject: jest.fn(),
		getObject: jest.fn(),
		setActiveObject: jest.fn(),
		availableRegisters: [],
		availableSchemas: [],
	},
}))

/**
 * A component context with every method bound to it and a router spy.
 *
 * @param {object} state Fields to override.
 * @return {object} The context.
 */
function vm(state = {}) {
	const ctx = { t: (app, text) => text, $router: { push: jest.fn() }, ...state }
	for (const [name, fn] of Object.entries(ViewCatalogi.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.availableRegisters = []
	objectStore.availableSchemas = []
})

describe('ViewCatalogi state', () => {
	it('opens on the registers tab of three', () => {
		expect(ViewCatalogi.data.call(vm())).toEqual({ activeTab: 0, tabs: ['Registers', 'Schemas', 'Organization'] })
	})

	it('shows the active catalogue', () => {
		const catalog = { id: 'c1' }
		objectStore.getActiveObject.mockReturnValue(catalog)

		expect(ViewCatalogi.computed.activeCatalog.call(vm())).toBe(catalog)
		expect(objectStore.getActiveObject).toHaveBeenCalledWith('catalog')
	})

	it('looks up the organisation of the active catalogue', () => {
		const organization = { id: 'o1', title: 'Gemeente' }
		objectStore.getObject.mockReturnValue(organization)

		expect(ViewCatalogi.computed.organization.call(vm({ activeCatalog: { organization: 'o1' } }))).toBe(organization)
		expect(objectStore.getObject).toHaveBeenCalledWith('organization', 'o1')
	})

	it('has no organisation without one on the catalogue, or without a catalogue', () => {
		expect(ViewCatalogi.computed.organization.call(vm({ activeCatalog: { organization: '' } }))).toBeNull()
		expect(ViewCatalogi.computed.organization.call(vm({ activeCatalog: null }))).toBeNull()
		expect(objectStore.getObject).not.toHaveBeenCalled()
	})
})

describe('ViewCatalogi lookups', () => {
	it('finds a register and a schema by id among the available ones', () => {
		objectStore.availableRegisters = [{ id: 1, title: 'Publicaties' }, { id: 2, title: 'Woo' }]
		objectStore.availableSchemas = [{ id: 10, title: 'Besluit' }]
		const ctx = vm()

		expect(ctx.getRegisterById(2)).toEqual({ id: 2, title: 'Woo' })
		expect(ctx.getSchemaById(10)).toEqual({ id: 10, title: 'Besluit' })
	})

	it('finds nothing for an unknown id, and does not match an id as text', () => {
		objectStore.availableRegisters = [{ id: 1 }]
		objectStore.availableSchemas = [{ id: 10 }]
		const ctx = vm()

		expect(ctx.getRegisterById(9)).toBeUndefined()
		expect(ctx.getSchemaById('10')).toBeUndefined()
	})
})

describe('ViewCatalogi actions', () => {
	it('closes the dialog', () => {
		vm().closeModal()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('switches to the edit form', () => {
		vm().editCatalog()

		expect(navigationStore.setModal).toHaveBeenCalledWith('catalog')
	})

	it('opens the catalogue publications by its slug and closes the dialog', () => {
		const ctx = vm({ activeCatalog: { slug: 'woo' } })

		ctx.viewCatalog()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.$router.push).toHaveBeenCalledWith('/publications/woo')
	})

	it('stays open and goes nowhere without a slug', () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		const ctx = vm({ activeCatalog: { id: 'c1' } })

		ctx.viewCatalog()

		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(error).toHaveBeenCalledWith('[ViewCatalogi#viewCatalog] Cannot navigate: catalog or slug is missing')
		error.mockRestore()
	})

	it('closes the dialog and asks to confirm the delete', () => {
		vm().deleteCatalog()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', { objectType: 'catalog', dialogTitle: 'Catalogus' })
	})

	it('opens the organisation on the organisations page', () => {
		const organization = { id: 'o1' }
		const ctx = vm({ organization })

		ctx.goToOrganization()

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('organization', organization)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.$router.push).toHaveBeenCalledWith('/organizations')
	})

	it('does nothing for a catalogue without an organisation', () => {
		const ctx = vm({ organization: null })

		ctx.goToOrganization()

		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(ctx.$router.push).not.toHaveBeenCalled()
	})
})
