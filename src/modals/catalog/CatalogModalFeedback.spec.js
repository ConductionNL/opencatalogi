/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the feedback of `CatalogModal.vue`: what it shows after
 * a save succeeded or failed, while it saves, which title it carries when
 * adding or editing, and that the staff access fields show for an
 * administrator only.
 */

import { mount } from '@vue/test-utils'
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
	},
}))

const stubs = {
	NcModal: { props: ['name'], template: '<div class="modal" :data-name="name"><slot /></div>' },
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
 * The notes shown, as type and text.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<Array<string>>}
 */
function notes(wrapper) {
	return wrapper.findAll('.note').map((note) => [note.attributes('data-type'), note.text()])
}

beforeEach(() => {
	admin.value = false
})

describe('CatalogModal feedback', () => {
	it('says a new catalogue was added and hides the form and the save button', () => {
		const wrapper = mountModal({ success: true })

		expect(notes(wrapper)).toEqual([['success', 'Catalog successfully added']])
		expect(wrapper.find('.form-group').exists()).toBe(false)
		expect(wrapper.findAll('.modalActions button').map((button) => button.text())).toEqual(['Cancel'])
	})

	it('says an edited catalogue was saved, under the edit title, with Close', () => {
		const wrapper = mountModal({ success: true, active: { id: 'c1', title: 'Woo', registers: [], schemas: [] } })

		expect(wrapper.find('.modal').attributes('data-name')).toBe('Catalog edit')
		expect(notes(wrapper)).toEqual([['success', 'Catalog successfully edited']])
		expect(wrapper.findAll('.modalActions button').map((button) => button.text())).toEqual(['Close'])
	})

	it('says adding failed and shows the error the store holds', () => {
		const wrapper = mountModal({ success: false, error: 'Title already taken' })

		expect(notes(wrapper)).toEqual([
			['error', 'Something went wrong while adding the catalog'],
			['error', 'Title already taken'],
		])
	})

	it('says editing failed', () => {
		const wrapper = mountModal({ success: false, active: { id: 'c1', title: 'Woo', registers: [], schemas: [] } })

		expect(notes(wrapper)).toEqual([['error', 'Something went wrong while editing the catalog']])
	})

	it('shows no note and the form before a save', () => {
		const wrapper = mountModal()

		expect(wrapper.find('.modal').attributes('data-name')).toBe('Add Catalog')
		expect(notes(wrapper)).toEqual([])
		expect(wrapper.find('.form-group').exists()).toBe(true)
		expect(wrapper.find('.loading-status').exists()).toBe(false)
	})
})

describe('CatalogModal while saving', () => {
	it('hides the form and the save button and says it is adding', () => {
		const wrapper = mountModal({ loading: true })

		expect(wrapper.find('.form-group').exists()).toBe(false)
		expect(wrapper.find('.loading-status').text()).toBe('Catalog is being added...')
		expect(wrapper.findAll('.modalActions button').map((button) => button.text())).toEqual(['Cancel'])
	})

	it('says it is editing for a catalogue being edited', () => {
		const wrapper = mountModal({ loading: true, active: { id: 'c1', title: 'Woo', registers: [], schemas: [] } })

		expect(wrapper.find('.loading-status').text()).toBe('Catalog is being edited...')
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
