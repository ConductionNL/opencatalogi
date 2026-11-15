/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `AddDirectoryModal.vue`'s report: how a sync time and a
 * listing action are shown, what the rendered report holds, the details
 * toggle, and what closing the modal resets.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import AddDirectoryModal from './AddDirectoryModal.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'addDirectory', setModal: jest.fn() },
}))

const DEFAULT_URL = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory'

const REPORT = {
	directory_url: 'https://peer.example/api/directory',
	sync_time: '2026-03-04T05:06:07Z',
	total_processed: 7,
	listings_created: 3,
	listings_updated: 2,
	listings_unchanged: 1,
	listings_failed: 1,
	errors: ['first problem', 'second problem'],
	listing_details: [
		{ listing_id: 'a', listing_title: 'Alpha', action: 'created', success: true },
		{ listing_id: 'b', action: 'failed', success: false, error: 'refused' },
	],
}

/**
 * Mount the modal.
 *
 * @return {object} The wrapper.
 */
function mountModal() {
	return mount(AddDirectoryModal, { global: { mocks: { t: (app, text) => text } } })
}

/**
 * Mount the modal and run a sync that answers with the given report.
 *
 * @param {object} report - The sync report
 * @return {Promise<object>} The wrapper.
 */
async function mountWithReport(report) {
	axios.post.mockResolvedValue({ data: report })
	const wrapper = mountModal()
	await wrapper.vm.handleSync()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('AddDirectoryModal report', () => {
	beforeEach(() => {
		const { navigationStore } = jest.requireMock('../../store/store.js')
		navigationStore.setModal.mockClear()
		axios.post.mockReset()
	})

	afterEach(() => {
		mountModal().vm.closeModal()
	})

	it.each([
		[null],
		[''],
		['not a date'],
	])('shows %j as Unknown', (value) => {
		expect(mountModal().vm.formatDateTime(value)).toBe('Unknown')
	})

	it('shows a sync time as a Dutch date and time down to the second', () => {
		const expected = new Date(REPORT.sync_time).toLocaleString('nl-NL', {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		})
		expect(mountModal().vm.formatDateTime(REPORT.sync_time)).toBe(expected)
	})

	it('shows Unknown when the date cannot be formatted', () => {
		const spy = jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(() => {
			throw new RangeError('bad locale')
		})
		try {
			expect(mountModal().vm.formatDateTime(REPORT.sync_time)).toBe('Unknown')
		} finally {
			spy.mockRestore()
		}
	})

	it.each([
		['created', 'New'],
		['updated', 'Updated'],
		['unchanged', 'Unchanged'],
		['failed', 'Failed'],
		['none', 'No action'],
		['merged', 'merged'],
	])('labels the action %s as %s', (action, label) => {
		expect(mountModal().vm.getActionLabel(action)).toBe(label)
	})

	it('renders the report counts and errors, without the form', async () => {
		const wrapper = await mountWithReport(REPORT)
		const text = wrapper.text()

		expect(text).toContain('Directory successfully synchronized')
		expect(text).toContain(REPORT.directory_url)
		expect(wrapper.findAll('.stat-item.error')).toHaveLength(1)
		expect(wrapper.findAll('.error-item').map((li) => li.text())).toEqual(['first problem', 'second problem'])
		expect(wrapper.find('.form-group').exists()).toBe(false)
		expect(wrapper.find('.listing-details').exists()).toBe(false)
		expect(text).toContain('Show details')
		expect(text).toContain('Close')
	})

	it('hides the failed count, errors and toggle when there are none', async () => {
		const wrapper = await mountWithReport({ ...REPORT, listings_failed: 0, errors: [], listing_details: [] })

		expect(wrapper.find('.stat-item.error').exists()).toBe(false)
		expect(wrapper.find('.sync-errors').exists()).toBe(false)
		expect(wrapper.find('.toggle-details').exists()).toBe(false)
	})

	it('shows each listing result once the details are toggled', async () => {
		const wrapper = await mountWithReport(REPORT)
		await wrapper.find('.toggle-details').trigger('click')

		const items = wrapper.findAll('.listing-item')
		expect(items).toHaveLength(2)
		expect(items[0].classes()).toContain('success')
		expect(items[0].find('.listing-title').text()).toBe('Alpha')
		expect(items[0].find('.listing-action').text()).toBe('New')
		expect(items[0].find('.listing-error').exists()).toBe(false)
		expect(items[1].classes()).toContain('error')
		expect(items[1].find('.listing-title').text()).toBe('b')
		expect(items[1].find('.listing-action').text()).toBe('Failed')
		expect(items[1].find('.listing-error').text()).toBe('refused')
		expect(wrapper.text()).toContain('Hide details')
	})

	it('renders a failed sync with the error and keeps the form', async () => {
		axios.post.mockRejectedValue({ message: 'offline' })
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mountModal()
		await wrapper.vm.handleSync()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Synchronization failed')
		expect(wrapper.text()).toContain('offline')
		expect(wrapper.text()).toContain('Cancel')
		console.error.mockRestore()
	})

	it('closes the modal and resets the report and url', async () => {
		const { navigationStore } = jest.requireMock('../../store/store.js')
		const wrapper = await mountWithReport(REPORT)
		wrapper.vm.directoryUrl = 'https://elsewhere.example'

		wrapper.vm.closeModal()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.success).toBeNull()
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.syncResults).toBeNull()
		expect(wrapper.vm.directoryUrl).toBe(DEFAULT_URL)
	})

	it('renders nothing while another modal is open', () => {
		const { navigationStore } = jest.requireMock('../../store/store.js')
		navigationStore.modal = 'other'
		try {
			expect(mountModal().find('.modal__content').exists()).toBe(false)
		} finally {
			navigationStore.modal = 'addDirectory'
		}
	})
})
