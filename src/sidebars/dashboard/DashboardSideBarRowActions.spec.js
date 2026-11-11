/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the row actions of `DashboardSideBar.vue`: every
 * publication and attachment action makes the row the active object of its
 * type and opens the modal or dialog it names. It also pins the quick
 * publication form's empty state and its reset.
 */

import { mount } from '@vue/test-utils'
import DashboardSideBar from './DashboardSideBar.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => {
	const slots = '<div><slot name="icon" /><slot name="subname" /><slot name="actions" /><slot /></div>'
	const plain = { template: '<div />' }
	return {
		NcAppSidebar: { template: slots },
		NcAppSidebarTab: { template: slots },
		NcListItem: { emits: ['click'], template: slots },
		NcActionButton: {
			emits: ['click'],
			template: '<button class="row-action" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
		},
		NcButton: plain,
		NcLoadingIcon: plain,
		NcNoteCard: plain,
		NcSelect: plain,
		NcTextField: plain,
	}
})

jest.mock('../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({ files: { value: [] }, reset: () => {} }),
}))

jest.mock('../../store/store.js', () => {
	const active = {}
	const collections = {
		publication: { results: [{ id: 'p1', title: 'Publication one' }] },
		attachment: { results: [{ id: 'a1', title: 'Attachment one' }] },
	}
	return {
		navigationStore: {
			setModal: jest.fn(),
			setDialog: jest.fn(),
		},
		objectStore: {
			active,
			getActiveObject: (type) => active[type] ?? null,
			setActiveObject: jest.fn((type, object) => {
				active[type] = object
			}),
			getCollection: (type) => collections[type] ?? { results: [] },
			fetchCollection: jest.fn(),
			getSearchTerm: () => '',
			setSearchTerm: jest.fn(),
			getError: () => null,
		},
	}
})

/**
 * Mount the sidebar with its data fetches switched off.
 *
 * @return {object} The wrapper.
 */
function mountSideBar() {
	jest.spyOn(DashboardSideBar.methods, 'fetchCatalogi').mockImplementation(() => {})
	jest.spyOn(DashboardSideBar.methods, 'fetchPublicationType').mockImplementation(() => {})
	return mount(DashboardSideBar, {
		global: {
			mocks: { t: (app, text) => text },
		},
	})
}

/**
 * Click one row action.
 *
 * @param {object} wrapper The mounted sidebar.
 * @param {{label: string, index: number}} action The button text, and which
 *   button with that text (0: publications, 1: attachments).
 * @return {Promise<void>}
 */
async function clickAction(wrapper, { label, index }) {
	const buttons = wrapper.findAll('button.row-action').filter((b) => b.text() === label)
	await buttons[index].trigger('click')
}

describe('DashboardSideBar row actions', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it.each([
		['Edit', 0, 'publication', 'p1', 'modal', 'editPublication'],
		['Publish', 0, 'publication', 'p1', 'dialog', 'publishPublication'],
		['Delete', 0, 'publication', 'p1', 'dialog', 'deletePublication'],
		['Edit', 1, 'attachment', 'a1', 'modal', 'editAttachment'],
		['Publish', 1, 'attachment', 'a1', 'dialog', 'publishAttachment'],
		['Delete', 1, 'attachment', 'a1', 'dialog', 'deleteAttachment'],
	])('%s (list %i) activates the %s and opens %s', async (label, index, type, id, kind, name) => {
		const wrapper = mountSideBar()
		await clickAction(wrapper, { label, index })

		expect(objectStore.setActiveObject).toHaveBeenCalledTimes(1)
		expect(objectStore.setActiveObject.mock.calls[0][0]).toBe(type)
		expect(objectStore.setActiveObject.mock.calls[0][1].id).toBe(id)
		const opener = kind === 'modal' ? navigationStore.setModal : navigationStore.setDialog
		const other = kind === 'modal' ? navigationStore.setDialog : navigationStore.setModal
		expect(opener).toHaveBeenCalledTimes(1)
		expect(opener.mock.calls[0][0]).toBe(name)
		expect(opener.mock.calls[0][1]).toBeUndefined()
		expect(other).not.toHaveBeenCalled()
	})

	it('renders the icon and label of every row action', () => {
		const wrapper = mountSideBar()
		const labels = wrapper.findAll('button.row-action').map((b) => b.text())

		expect(labels).toEqual(['View', 'Edit', 'Publish', 'Delete', 'Edit', 'Publish', 'Delete'])
	})

	it('starts with an empty quick-publication form', () => {
		// The component mixes <script setup> and options; its own proxy holds
		// the options state.
		const vm = mountSideBar().vm.$.proxy

		expect(vm.publicationItem).toEqual({
			title: '',
			summary: '',
			reference: '',
			category: '',
			portal: '',
			license: '',
		})
	})

	it('cleanup empties the form after a success and clears the error', () => {
		// The component mixes <script setup> and options; its own proxy holds
		// the options state.
		const vm = mountSideBar().vm.$.proxy
		const before = vm.publicationItem
		vm.publicationItem = { ...before, title: 'Typed', summary: 'Text' }
		vm.success = true
		vm.error = 'boom'
		vm.cleanup()

		expect(vm.publicationItem).toEqual(before)
		expect(vm.catalogi.value).toEqual([])
		expect(vm.publicationType.value).toEqual([])
		expect(vm.error).toBeNull()
	})

	it('cleanup keeps the form when the add failed', () => {
		// The component mixes <script setup> and options; its own proxy holds
		// the options state.
		const vm = mountSideBar().vm.$.proxy
		vm.publicationItem = { title: 'Typed' }
		vm.success = false
		vm.error = 'boom'
		vm.cleanup()

		expect(vm.publicationItem).toEqual({ title: 'Typed' })
		expect(vm.error).toBeNull()
	})
})
