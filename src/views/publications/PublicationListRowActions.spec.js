/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the row actions of `PublicationList.vue`: each action
 * makes the row the active publication and then opens its modal or dialog,
 * or publishes, depublishes or opens the file list.
 */

import { mount } from '@vue/test-utils'
import PublicationList from './PublicationList.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => {
	const slots = '<div><slot name="icon" /><slot name="subname" /><slot name="actions" /><slot /></div>'
	const plain = { template: '<div />' }
	return {
		NcActionButton: {
			emits: ['click'],
			template: '<button class="row-action" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
		},
		NcActionCaption: plain,
		NcActionCheckbox: plain,
		NcActionInput: plain,
		NcActionRadio: plain,
		NcActions: plain,
		NcActionSeparator: plain,
		NcAppContentList: { template: slots },
		NcListItem: { emits: ['click'], template: slots },
		NcLoadingIcon: plain,
		NcTextField: plain,
	}
})

jest.mock('../../store/store.js', () => {
	const active = {}
	const publications = [
		{ id: 'p1', title: 'Draft', '@self': { published: null } },
		{ id: 'p2', title: 'Live', '@self': { published: '2026-01-01' } },
	]
	return {
		catalogStore: { isLoading: false, fetchPublications: jest.fn() },
		navigationStore: {
			setModal: jest.fn(),
			setDialog: jest.fn(),
			setTransferData: jest.fn(),
		},
		objectStore: {
			getActiveObject: (type) => active[type] ?? null,
			setActiveObject: jest.fn((type, object) => {
				active[type] = object
			}),
			clearActiveObject: jest.fn(),
			getCollection: () => ({ results: publications }),
			isLoading: () => false,
			getSearchTerm: () => '',
			setSearchTerm: jest.fn(),
			clearSearchTerm: jest.fn(),
		},
	}
})

/**
 * Mount the list.
 *
 * @return {object} The wrapper.
 */
function mountList() {
	return mount(PublicationList, {
		global: {
			mocks: { t: (app, text) => text, $route: { params: {} } },
		},
	})
}

/**
 * The row action buttons of one row.
 *
 * @param {object} wrapper The mounted list.
 * @param {number} row The row index.
 * @return {Array<object>} Its buttons.
 */
function rowButtons(wrapper, row) {
	return wrapper.findAll('.row-action').filter((b) => !['Help', 'Refresh', 'Add publication'].includes(b.text()))
		.slice(row * 5, row * 5 + 5)
}

/**
 * Click the button with `label` in row `row`.
 *
 * @param {object} wrapper The mounted list.
 * @param {{row: number, label: string}} action Which button.
 * @return {Promise<void>}
 */
async function click(wrapper, { row, label }) {
	await rowButtons(wrapper, row).find((b) => b.text() === label).trigger('click')
}

describe('PublicationList row actions', () => {
	let publish

	beforeEach(() => {
		jest.clearAllMocks()
		publish = jest.spyOn(PublicationList.methods, 'publishPublication').mockImplementation(() => {})
	})

	afterEach(() => {
		publish.mockRestore()
	})

	it('shows Publish on a draft and Depublish on a published row', () => {
		const wrapper = mountList()

		expect(rowButtons(wrapper, 0).map((b) => b.text())).toEqual(['Edit', 'Copy', 'Publish', 'File list', 'Delete'])
		expect(rowButtons(wrapper, 1).map((b) => b.text())).toEqual(['Edit', 'Copy', 'Depublish', 'File list', 'Delete'])
	})

	it('Edit opens the object modal on the row', async () => {
		const wrapper = mountList()
		await click(wrapper, { row: 0, label: 'Edit' })

		expect(objectStore.setActiveObject.mock.calls).toEqual([['publication', expect.objectContaining({ id: 'p1' })]])
		expect(navigationStore.setModal.mock.calls).toEqual([['objectModal']])
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('Copy opens the copy dialog with its properties', async () => {
		const wrapper = mountList()
		await click(wrapper, { row: 1, label: 'Copy' })

		expect(objectStore.setActiveObject.mock.calls).toEqual([['publication', expect.objectContaining({ id: 'p2' })]])
		expect(navigationStore.setDialog.mock.calls).toEqual([['copyObject', { objectType: 'publication', dialogTitle: 'Publication' }]])
	})

	it('Delete opens the delete dialog with its properties', async () => {
		const wrapper = mountList()
		await click(wrapper, { row: 0, label: 'Delete' })

		expect(objectStore.setActiveObject.mock.calls).toEqual([['publication', expect.objectContaining({ id: 'p1' })]])
		expect(navigationStore.setDialog.mock.calls).toEqual([['deleteObject', { objectType: 'publication', dialogTitle: 'Publicatie' }]])
	})

	it.each([
		[0, 'Publish', 'publish', 'p1'],
		[1, 'Depublish', 'depublish', 'p2'],
	])('row %i %s activates the row and then runs %s', async (row, label, mode, id) => {
		const wrapper = mountList()
		await click(wrapper, { row, label })

		expect(objectStore.setActiveObject.mock.calls).toEqual([['publication', expect.objectContaining({ id })]])
		expect(publish.mock.calls).toEqual([[mode]])
		expect(objectStore.setActiveObject.mock.invocationCallOrder[0]).toBeLessThan(publish.mock.invocationCallOrder[0])
	})

	it('File list opens the view modal on its Files tab', async () => {
		const wrapper = mountList()
		await click(wrapper, { row: 0, label: 'File list' })

		expect(objectStore.setActiveObject.mock.calls).toEqual([['publication', expect.objectContaining({ id: 'p1' })]])
		expect(navigationStore.setTransferData.mock.calls).toEqual([[{ initialTab: 'files' }]])
		expect(navigationStore.setModal.mock.calls).toEqual([['viewObject']])
		expect(navigationStore.setTransferData.mock.invocationCallOrder[0]).toBeLessThan(navigationStore.setModal.mock.invocationCallOrder[0])
	})
})
