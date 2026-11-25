/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the feedback of `CatalogModal.vue`: what it shows after
 * a save succeeded or failed, while it saves, which title it carries when
 * adding or editing, and that the staff access fields show for an
 * administrator only.
 */

import { flushPromises, mount } from '@vue/test-utils'
import CatalogModal from './CatalogModal.vue'
import { adminForTest as admin } from '../../composables/useIsAdmin.js'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(() => Promise.resolve({ data: {} })),
	post: jest.fn(() => Promise.resolve({ data: {} })),
}))
jest.mock('../../composables/useIsAdmin.js', () => {
	const isAdmin = jest.requireActual('vue').ref(false)
	return {
		adminForTest: isAdmin,
		useIsAdmin: () => ({ isAdmin, loaded: { value: true } }),
	}
})
jest.mock('../../services/nextcloudGroups.js', () => ({
	getNextcloudGroups: () => Promise.resolve([]),
}))

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'catalog', setModal: jest.fn() },
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		active: null,
		state: { success: null, error: null },
		loading: false,
		getActiveObject() {
			return this.active
		},
		getCollection: () => ({ results: [] }),
		getState() {
			return this.state
		},
		isLoading() {
			return this.loading
		},
		setState: jest.fn(),
		createObject: jest.fn(() => Promise.resolve()),
		updateObject: jest.fn(() => Promise.resolve()),
	},
}))

const stubs = {
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	CatalogStaffAccessFields: { template: '<div class="staff-access" />' },
}

/**
 * Mount the modal over the given store state.
 *
 * @param {object} state The catalog state: success, error, loading, active.
 * @return {object} The wrapper.
 */
function mountModal({ success = null, error = null, loading = false, active = null } = {}) {
	objectStore.state = { success, error }
	objectStore.loading = loading
	objectStore.active = active
	return mount(CatalogModal, { global: { stubs, mocks: { t: (app, text) => text } } })
}

/**
 * The results the dialog shows, as type and text.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<Array<string>>}
 */
function results(wrapper) {
	return wrapper.findAll('.cn-form-dialog-result').map((note) => [note.attributes('data-type'), note.text()])
}

/**
 * The dialog's button labels.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<string>}
 */
function buttons(wrapper) {
	return wrapper.findAll('.cn-form-dialog-actions button').map((button) => button.text())
}

/**
 * Fill the form so it validates.
 *
 * @param {object} vm The modal.
 * @return {void}
 */
function fillValid(vm) {
	vm.catalogi.title = 'Woo'
	vm.catalogi.slug = 'woo'
	vm.$data.selectedRegisters = [{ id: 1, label: 'Register A' }]
	vm.$data.selectedSchemas = [{ id: 10, label: 'Ten (Register A)' }]
}

const ACTIVE = { id: 'c1', title: 'Woo', registers: [], schemas: [] }

beforeEach(() => {
	admin.value = false
	objectStore.createObject.mockReset().mockImplementation(() => Promise.resolve())
	objectStore.updateObject.mockReset().mockImplementation(() => Promise.resolve())
})

describe('CatalogModal feedback', () => {
	it('says a new catalogue was added and hides the form and the save button', async () => {
		const wrapper = mountModal()
		await wrapper.vm.saveCatalog()
		await flushPromises()

		expect(results(wrapper)).toEqual([['success', 'Catalog successfully added']])
		expect(wrapper.find('.form-group').exists()).toBe(false)
		expect(buttons(wrapper)).toEqual(['Close'])
	})

	it('says an edited catalogue was saved, under the edit title, with Close', async () => {
		const wrapper = mountModal({ active: ACTIVE })
		await wrapper.vm.saveCatalog()
		await flushPromises()

		expect(wrapper.find('.cn-form-dialog-stub').attributes('data-name')).toBe('Catalog edit')
		expect(results(wrapper)).toEqual([['success', 'Catalog successfully edited']])
		expect(buttons(wrapper)).toEqual(['Close'])
	})

	it('says adding failed with the error the store rejects with', async () => {
		objectStore.createObject.mockImplementation(() => Promise.reject(new Error('Title already taken')))
		const wrapper = mountModal()
		await wrapper.vm.saveCatalog()
		await flushPromises()

		expect(results(wrapper)).toEqual([['error', 'Title already taken']])
	})

	it('says editing failed when the store gives no message', async () => {
		objectStore.updateObject.mockImplementation(() => Promise.reject(new Error('')))
		const wrapper = mountModal({ active: ACTIVE })
		await wrapper.vm.saveCatalog()
		await flushPromises()

		expect(results(wrapper)).toEqual([['error', 'Something went wrong while editing the catalog']])
	})

	it('shows no result and the form before a save', () => {
		const wrapper = mountModal()

		expect(wrapper.find('.cn-form-dialog-stub').attributes('data-name')).toBe('Add Catalog')
		expect(results(wrapper)).toEqual([])
		expect(wrapper.find('.form-group').exists()).toBe(true)
		expect(buttons(wrapper)).toEqual(['Cancel', 'Add'])
	})
})

describe('CatalogModal confirm button', () => {
	it('stays disabled until the form validates, then saves on a click', async () => {
		const wrapper = mountModal()
		expect(wrapper.find('.cn-form-dialog-confirm').attributes('disabled')).toBeDefined()

		fillValid(wrapper.vm)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.cn-form-dialog-confirm').attributes('disabled')).toBeUndefined()

		await wrapper.find('.cn-form-dialog-confirm').trigger('click')
		await flushPromises()
		expect(objectStore.createObject).toHaveBeenCalledTimes(1)
	})

	it('labels the buttons Close and Save for a catalogue being edited', () => {
		expect(buttons(mountModal({ active: ACTIVE }))).toEqual(['Close', 'Save'])
	})
})

describe('CatalogModal staff access', () => {
	it('shows the staff access fields to an administrator only', () => {
		expect(mountModal().find('.staff-access').exists()).toBe(false)

		admin.value = true
		expect(mountModal().find('.staff-access').exists()).toBe(true)
	})
})

describe('CatalogModal form fields', () => {
	it('writes every field into the catalogue it saves', async () => {
		admin.value = true
		const wrapper = mountModal()
		// The four text fields in the form's order: title, summary, description, slug.
		const text = wrapper.findAllComponents({ name: 'NcTextField' })
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		const selects = Object.fromEntries(wrapper.findAllComponents({ name: 'NcSelect' }).map((select) => [select.props('inputLabel'), select]))

		expect(text).toHaveLength(4)
		for (const [index, value] of ['Woo', 'Actief openbaar', 'De Woo-catalogus', 'woo'].entries()) {
			text[index].vm.$emit('update:modelValue', value)
		}
		for (const toggle of switches) {
			toggle.vm.$emit('update:modelValue', true)
		}
		selects['Status*'].vm.$emit('update:modelValue', { id: 'stable', label: 'Stable' })
		selects.Organization.vm.$emit('update:modelValue', { id: 'org-1', label: 'Gemeente' })
		wrapper.findComponent(stubs.CatalogStaffAccessFields).vm.$emit('update:modelValue', { mode: 'restricted' })
		await wrapper.vm.$nextTick()

		const item = wrapper.vm.catalogItem()
		expect([item.title, item.summary, item.description, item.slug]).toEqual(['Woo', 'Actief openbaar', 'De Woo-catalogus', 'woo'])
		expect([item.listed, item.hasWooSitemap, item.hasOoapi, item.showWithheld]).toEqual([true, true, true, true])
		expect([item.status, item.organization]).toEqual(['stable', 'org-1'])
		expect(wrapper.vm.catalogi.staffAccess).toEqual({ mode: 'restricted' })
	})
})
