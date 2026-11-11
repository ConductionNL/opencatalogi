/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for MassAttachmentModal: which attachments it acts
 * on, what publishing or depublishing them does to its state, the store and
 * the dialog, and its title.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import MassAttachmentModal from './MassAttachmentModal.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { dialogProperties: {}, setDialog: jest.fn() },
	objectStore: {
		selectedAttachments: [],
		files: [],
		getRelatedData() {
			return { results: this.files }
		},
		massPublishAttachments: jest.fn(),
		massDepublishAttachments: jest.fn(),
	},
	catalogStore: { fetchPublications: jest.fn() },
}))

/**
 * Mount the modal for an operation on the given attachments.
 *
 * @param {string} operation publish or depublish.
 * @return {object} The modal.
 */
function open(operation) {
	navigationStore.dialogProperties = { operation, attachments: ['a', 'b', 'c', 'd'] }
	return shallowMount(MassAttachmentModal, { global: { mocks: { t: (app, text) => text } } }).vm
}

/**
 * What a run left behind.
 *
 * @param {object} vm The modal.
 * @return {object} The trace.
 */
function trace(vm) {
	return {
		success: vm.success,
		error: vm.error,
		loading: vm.loading,
		timeout: vm.closeModalTimeout !== null,
		publish: objectStore.massPublishAttachments.mock.calls,
		depublish: objectStore.massDepublishAttachments.mock.calls,
		refreshed: catalogStore.fetchPublications.mock.calls.length,
		closed: navigationStore.setDialog.mock.calls,
	}
}

beforeEach(() => {
	jest.useFakeTimers()
	objectStore.files = [
		{ id: 'a' },
		{ id: 'b', accessUrl: 'https://x/a' },
		{ id: 'c', downloadUrl: 'https://x/c' },
	]
	objectStore.massPublishAttachments.mockReset()
	objectStore.massDepublishAttachments.mockReset()
	catalogStore.fetchPublications.mockReset()
	navigationStore.setDialog.mockReset()
})

describe.each(['publish', 'depublish'])('%s', (operation) => {
	const run = operation === 'publish' ? 'massPublishAttachments' : 'massDepublishAttachments'

	it('acts on the attachments the operation applies to, with its title', () => {
		const vm = open(operation)
		expect([vm.filteredAttachmentIds, vm.filteredCount, vm.dialogTitle, vm.originalSelectedCount]).toMatchSnapshot()
	})

	it('closes itself two seconds after everything succeeded', async () => {
		objectStore[run].mockResolvedValue({ successful: ['x'], failed: [] })
		const vm = open(operation)
		await vm.process()
		await flushPromises()
		const before = trace(vm)
		jest.advanceTimersByTime(2000)
		expect([before, trace(vm)]).toMatchSnapshot()
	})

	it('stays open and names the failures when some failed', async () => {
		objectStore[run].mockResolvedValue({ successful: ['x'], failed: ['y'] })
		const vm = open(operation)
		await vm.process()
		jest.advanceTimersByTime(2000)
		expect(trace(vm)).toMatchSnapshot()
	})

	it('names the failures when all failed', async () => {
		objectStore[run].mockResolvedValue({ successful: [], failed: ['y', 'z'] })
		const vm = open(operation)
		await vm.process()
		expect(trace(vm)).toMatchSnapshot()
	})

	it('shows what the store threw', async () => {
		objectStore[run].mockRejectedValue(new Error('Server says no'))
		const vm = open(operation)
		await vm.process()
		expect(trace(vm)).toMatchSnapshot()
	})

	it('words an error without a message', async () => {
		objectStore[run].mockRejectedValue(new Error(''))
		const vm = open(operation)
		await vm.process()
		expect(trace(vm)).toMatchSnapshot()
	})
})

it('names one attachment in the singular', () => {
	objectStore.files = [{ id: 'a' }]
	expect(open('publish').dialogTitle).toBe('Publish attachment')
	objectStore.files = [{ id: 'b', accessUrl: 'u' }]
	expect(open('depublish').dialogTitle).toBe('Depublish attachment')
})
