/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `AddDirectoryModal.vue`'s sync: a success stores the
 * report, a failure shows the server's error, else its message, else the
 * request error's message, else the fixed Dutch fallback.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import AddDirectoryModal from './AddDirectoryModal.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => {
	const plain = { template: '<div><slot /></div>' }
	return {
		NcButton: plain,
		NcLoadingIcon: plain,
		NcModal: plain,
		NcNoteCard: plain,
		NcTextField: plain,
	}
})
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'addDirectory', setModal: jest.fn() },
}))

/**
 * Mount the modal and run one sync.
 *
 * @return {Promise<object>} The component instance.
 */
async function syncOnce() {
	const wrapper = mount(AddDirectoryModal, { global: { mocks: { t: (app, text) => text } } })
	await wrapper.vm.handleSync()
	return wrapper.vm
}

describe('AddDirectoryModal sync', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		axios.post.mockReset()
	})

	it('posts the directory url and stores the bare report', async () => {
		axios.post.mockResolvedValue({ data: { added: 1 } })
		const vm = await syncOnce()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/listings/add', {
			url: 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
		})
		expect(vm.success).toBe(true)
		expect(vm.error).toBeNull()
		expect(vm.syncResults).toEqual({ added: 1 })
		expect(vm.loading).toBe(false)
	})

	it('unwraps a nested data report', async () => {
		axios.post.mockResolvedValue({ data: { data: { added: 2 } } })
		const vm = await syncOnce()

		expect(vm.syncResults).toEqual({ added: 2 })
	})

	it.each([
		['the server error', { response: { data: { error: 'E', message: 'M' } }, message: 'X' }, 'E'],
		['the server message', { response: { data: { message: 'M' } }, message: 'X' }, 'M'],
		['the request message', { response: {}, message: 'X' }, 'X'],
		['the fallback', {}, 'Er is een fout opgetreden bij het synchroniseren'],
	])('shows %s on failure', async (label, err, expected) => {
		axios.post.mockRejectedValue(err)
		const vm = await syncOnce()

		expect(vm.success).toBe(false)
		expect(vm.error).toBe(expected)
		expect(vm.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error synchronizing directory:', err)
	})
})
