/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for DeleteAttachmentDialog: it deletes the active attachment
 * through OpenRegister's files endpoint of the active publication, announces
 * the change only when the delete succeeded, reloads the attachment list and
 * closes itself two seconds later.
 */

import { dispatchObjectsChanged } from '@conduction/nextcloud-vue'
import { flushPromises, shallowMount } from '@vue/test-utils'
import DeleteAttachmentDialog from './DeleteAttachmentDialog.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	objectStore: {
		currentPage: 3,
		limit: 20,
		active: {},
		getActiveObject(type) {
			return this.active[type]
		},
	},
	catalogStore: { getPublicationAttachments: jest.fn() },
}))

jest.mock('@conduction/nextcloud-vue', () => ({ dispatchObjectsChanged: jest.fn() }))

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'csrf-token' }))

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => path.replace(/{(\w+)}/g, (match, key) => String(params[key])),
}))

/**
 * Mount the dialog for the active publication and attachment.
 *
 * @return {object} The wrapper.
 */
function open() {
	objectStore.active = {
		publication: { id: 'pub-1', '@self': { register: 'reg-5', schema: 'sch-9' } },
		publicationAttachment: { id: 'file-7', title: 'Besluit.pdf' },
	}
	return shallowMount(DeleteAttachmentDialog, {
		global: {
			mocks: { t: (app, text, vars) => text.replace('{name}', vars?.name ?? '') },
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' }, NcNoteCard: { template: '<div class="note"><slot /></div>' } },
		},
	})
}

/**
 * Mount the dialog with buttons that render their icon and label and can be pressed.
 *
 * @return {object} The wrapper.
 */
function openWithButtons() {
	objectStore.active = {
		publication: { id: 'pub-1', '@self': { register: 'reg-5', schema: 'sch-9' } },
		publicationAttachment: { id: 'file-7', title: 'Besluit.pdf' },
	}
	return shallowMount(DeleteAttachmentDialog, {
		global: {
			mocks: { t: (app, text, vars) => text.replace('{name}', vars?.name ?? '') },
			stubs: {
				NcDialog: { template: '<div><slot /><footer><slot name="actions" /></footer></div>' },
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
				},
				NcLoadingIcon: { template: '<span class="spinner" />' },
			},
		},
	})
}

describe('DeleteAttachmentDialog', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		global.fetch = jest.fn()
	})

	afterEach(() => {
		jest.clearAllMocks()
		jest.useRealTimers()
		delete global.fetch
	})

	it('asks before deleting, naming the attachment', () => {
		expect(open().text()).toContain('Do you want to delete Besluit.pdf? This action cannot be undone.')
	})

	it('deletes through the files endpoint, announces the change and closes after two seconds', async () => {
		global.fetch.mockResolvedValue({ status: 200 })
		const wrapper = open()

		wrapper.vm.DeleteAttachment()
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()

		expect(global.fetch).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/reg-5/sch-9/pub-1/files/file-7',
			{ method: 'DELETE', headers: { requesttoken: 'csrf-token' } },
		)
		expect(wrapper.vm.succes).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
		expect(dispatchObjectsChanged).toHaveBeenCalledWith({ register: 'reg-5', schema: 'sch-9', id: 'pub-1' })
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledWith('pub-1', { page: 3, limit: 20 })
		expect(wrapper.text()).toContain('Attachment successfully deleted')

		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('announces nothing when the delete is refused, but still reloads and closes', async () => {
		global.fetch.mockResolvedValue({ status: 403 })
		const wrapper = open()

		wrapper.vm.DeleteAttachment()
		await flushPromises()

		expect(wrapper.vm.succes).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
		expect(dispatchObjectsChanged).not.toHaveBeenCalled()
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).not.toContain('Attachment successfully deleted')

		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('offers Cancel and Delete, and Cancel closes without deleting', async () => {
		const wrapper = openWithButtons()

		const buttons = wrapper.findAll('footer button')
		expect(buttons.map((button) => button.text())).toEqual(['Cancel', 'Delete'])
		expect(buttons[1].find('.spinner').exists()).toBe(false)
		expect(buttons.some((button) => button.attributes('disabled') !== undefined)).toBe(false)

		await buttons[0].trigger('click')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it('deletes from its button with a spinner, then offers only Close', async () => {
		let answer
		global.fetch.mockImplementation(() => new Promise((resolve) => { answer = resolve }))
		const wrapper = openWithButtons()

		await wrapper.findAll('footer button')[1].trigger('click')
		expect(global.fetch).toHaveBeenCalledTimes(1)
		const deleting = wrapper.findAll('footer button')
		expect(deleting[1].find('.spinner').exists()).toBe(true)
		expect(deleting.every((button) => button.attributes('disabled') !== undefined)).toBe(true)

		answer({ status: 200 })
		await flushPromises()

		const done = wrapper.findAll('footer button')
		expect(done.map((button) => button.text())).toEqual(['Close'])
	})
})
