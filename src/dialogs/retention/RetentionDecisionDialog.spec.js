/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `RetentionDecisionDialog.vue`: one decision request per
 * publication, a note is required, an extension needs months.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RetentionDecisionDialog from './RetentionDecisionDialog.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key) =>
		({ publication_register: '31', publication_schema: '173' })[key],
}))

const parking = {
	id: 'p1',
	title: 'Woo-besluit over de parkeervergunningen van 2021',
	retentionExpiresAt: '2026-10-04T00:00:00+00:00',
}
const transport = {
	id: 'p2',
	title: 'Convenant leerlingenvervoer 2016 tot 2026',
	retentionExpiresAt: '2026-10-01T00:00:00+00:00',
}

/**
 * @param {object} properties The dialog properties.
 * @return {object} The wrapper.
 */
function open(properties) {
	navigationStore.setDialog('retentionDecision', properties)
	return mount(RetentionDecisionDialog)
}

describe('RetentionDecisionDialog', () => {
	beforeEach(() => {
		jest.spyOn(console, 'log').mockImplementation(() => {})
		axios.get.mockImplementation((url) =>
			Promise.resolve({ data: url.endsWith('/p2') ? transport : parking }),
		)
		axios.post.mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		jest.clearAllMocks()
		jest.restoreAllMocks()
	})

	it('reads the selected publications it was not handed', async () => {
		const wrapper = open({
			decision: 'extend',
			ids: ['p1', 'p2'],
			publications: [parking],
		})
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toMatch(
			/\/apps\/openregister\/api\/objects\/31\/173\/p2$/,
		)
		expect(wrapper.findAll('li').map((li) => li.attributes('data-id'))).toEqual([
			'p1',
			'p2',
		])
	})

	it('extends each publication with the months and the note', async () => {
		const wrapper = open({
			decision: 'extend',
			ids: ['p1', 'p2'],
			publications: [parking, transport],
		})
		await flushPromises()

		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.note =
			'Beide spelen nog een rol in het beroep dat loopt tot medio 2027.'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)

		await wrapper.vm.submit()

		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(axios.post.mock.calls[0][0]).toMatch(
			/\/apps\/opencatalogi\/api\/retention\/publications\/p1\/decision$/,
		)
		expect(axios.post.mock.calls[1][1]).toEqual({
			decision: 'extend',
			note: 'Beide spelen nog een rol in het beroep dat loopt tot medio 2027.',
			extendMonths: 12,
		})
		expect(wrapper.vm.done).toBe(true)
		expect(wrapper.vm.failed).toEqual([])
	})

	it('archives without months', async () => {
		const wrapper = open({
			decision: 'archive',
			ids: ['p1'],
			publications: [parking],
		})
		await flushPromises()
		wrapper.vm.note = 'Termijn verstreken.'

		await wrapper.vm.submit()

		expect(axios.post.mock.calls[0][1]).toEqual({
			decision: 'archive',
			note: 'Termijn verstreken.',
		})
	})

	it('names the publications the server refused', async () => {
		axios.post.mockImplementation((url) =>
			url.includes('/p2/')
				? Promise.reject(new Error('400'))
				: Promise.resolve({ data: {} }),
		)
		const wrapper = open({
			decision: 'depublish',
			ids: ['p1', 'p2'],
			publications: [parking, transport],
		})
		await flushPromises()
		wrapper.vm.note = 'Niet meer nodig.'

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.failed).toEqual([transport])
		expect(wrapper.text()).toContain(transport.title)
	})

	it('refuses an extension without months', async () => {
		const wrapper = open({
			decision: 'extend',
			ids: ['p1'],
			publications: [parking],
		})
		await flushPromises()
		wrapper.vm.note = 'Beroep loopt.'
		wrapper.vm.months = '0'

		expect(wrapper.vm.canSubmit).toBe(false)
	})
})
