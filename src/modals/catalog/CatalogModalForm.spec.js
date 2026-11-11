/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the form of `CatalogModal.vue`: what it validates,
 * what it saves when adding and when editing, how it fills itself from the
 * active catalog, how it resets on close, and how the schema options are
 * deduplicated.
 */

import { flushPromises, mount } from '@vue/test-utils'
import CatalogModal from './CatalogModal.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../composables/useIsAdmin.js', () => ({
	useIsAdmin: () => ({ isAdmin: { value: false }, loaded: { value: true } }),
}))
jest.mock('../../services/nextcloudGroups.js', () => ({
	getNextcloudGroups: () => Promise.resolve([]),
}))

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'catalog', setModal: jest.fn() },
	objectStore: {
		availableRegisters: [
			{ id: 1, title: 'Register A', schemas: [{ id: 10 }, { id: 11 }] },
			{ id: 2, title: 'Register B', schemas: [{ id: 11 }, { id: 12 }] },
		],
		availableSchemas: [
			{ id: 10, title: 'Ten', registerTitle: 'Register A' },
			{ id: 11, title: 'Eleven', registerTitle: 'Register A' },
			{ id: 11, title: 'Eleven', registerTitle: 'Register B' },
			{ id: 12, title: 'Twelve', registerTitle: 'Register B' },
		],
		active: null,
		getActiveObject() {
			return this.active
		},
		getCollection: () => ({ results: [{ id: 'org-1', name: 'Gemeente' }] }),
		getState: () => ({ success: null }),
		isLoading: () => false,
		setState: jest.fn(),
		createObject: jest.fn(() => Promise.resolve()),
		updateObject: jest.fn(() => Promise.resolve()),
	},
}))

/**
 * Mount the modal.
 *
 * @return {object} The wrapper.
 */
function mountModal() {
	return mount(CatalogModal, {
		global: {
			mocks: { t: (app, text) => text },
		},
	})
}

/**
 * The form state a snapshot records.
 *
 * @param {object} vm The modal.
 * @return {object} The state.
 */
function formState(vm) {
	return {
		catalogi: JSON.parse(JSON.stringify(vm.catalogi)),
		selectedOrganization: vm.$data.selectedOrganization,
		selectedRegisters: vm.$data.selectedRegisters,
		selectedSchemas: vm.$data.selectedSchemas,
		hasUpdated: vm.$data.hasUpdated,
	}
}

/**
 * Fill the form of an added catalog.
 *
 * @param {object} vm The modal.
 * @param {object|string} status The status as the select holds it.
 * @return {void}
 */
function fill(vm, status) {
	vm.catalogi.title = 'Woo'
	vm.catalogi.summary = 'Woo documents'
	vm.catalogi.slug = 'woo'
	vm.catalogi.listed = true
	vm.catalogi.status = status
	vm.catalogi.filters = null
	vm.$data.selectedOrganization = { id: 'org-1', label: 'Gemeente' }
	vm.$data.selectedRegisters = [{ id: 1, label: 'Register A' }]
	vm.$data.selectedSchemas = [{ id: 10, label: 'Ten (Register A)' }]
}

beforeEach(() => {
	jest.useRealTimers()
	objectStore.active = null
	objectStore.createObject.mockClear()
	objectStore.updateObject.mockClear()
	objectStore.setState.mockClear()
	navigationStore.setModal.mockClear()
	navigationStore.modal = 'catalog'
})

describe('CatalogModal form', () => {
	it('starts empty and invalid', () => {
		const vm = mountModal().vm
		expect([formState(vm), vm.inputValidation]).toMatchSnapshot()
	})

	it('validates a filled form, with the status as an option or as text', async () => {
		const vm = mountModal().vm
		fill(vm, { id: 'beta', label: 'Beta' })
		await vm.$nextTick()
		const asOption = vm.inputValidation
		vm.catalogi.status = 'Stable'
		await vm.$nextTick()
		expect([asOption, vm.inputValidation]).toMatchSnapshot()
	})

	it('adds the catalog without an id, then resets and closes after two seconds', async () => {
		jest.useFakeTimers()
		const vm = mountModal().vm
		fill(vm, { id: 'beta', label: 'Beta' })
		vm.catalogi.id = 'should-go'
		vm.saveCatalog()
		await flushPromises()
		expect(objectStore.createObject.mock.calls).toMatchSnapshot()
		expect(objectStore.updateObject).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1999)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect([navigationStore.setModal.mock.calls, objectStore.setState.mock.calls, formState(vm)]).toMatchSnapshot()
	})

	it('fills itself once from the active catalog and saves it as an edit', async () => {
		jest.useFakeTimers()
		objectStore.active = {
			id: 'cat-1',
			title: 'Bestaand',
			slug: 'bestaand',
			status: 'stable',
			organization: 'org-1',
			registers: [2, 9],
			schemas: [12],
			listed: true,
		}
		const wrapper = mountModal()
		const vm = wrapper.vm
		CatalogModal.updated.call(vm.$.proxy)
		const filled = formState(vm)
		vm.catalogi.title = 'Changed'
		CatalogModal.updated.call(vm.$.proxy)
		expect([filled, formState(vm)]).toMatchSnapshot()
		vm.saveCatalog()
		await flushPromises()
		expect(objectStore.updateObject.mock.calls).toMatchSnapshot()
		expect(objectStore.createObject).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal.mock.calls).toMatchSnapshot()
	})

	it('does not fill itself while another modal is open or when adding', () => {
		navigationStore.modal = 'other'
		const vm = mountModal().vm
		CatalogModal.updated.call(vm.$.proxy)
		const closed = formState(vm)
		navigationStore.modal = 'catalog'
		CatalogModal.updated.call(vm.$.proxy)
		expect([closed, formState(vm)]).toMatchSnapshot()
	})

	it('lists a schema two selected registers share once', async () => {
		const vm = mountModal().vm
		vm.$data.selectedRegisters = [{ id: 1, label: 'Register A' }, { id: 2, label: 'Register B' }]
		await vm.$nextTick()
		const all = vm.schemaOptions
		vm.$data.selectedSchemas = [{ id: 11, label: 'Eleven (Register A)' }]
		await vm.$nextTick()
		expect([all, vm.schemaOptions, vm.organizationOptions, vm.registerOptions]).toMatchSnapshot()
	})
})
