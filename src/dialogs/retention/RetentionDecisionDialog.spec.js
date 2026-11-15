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

// @nextcloud/auth ships ESM that Jest does not transform; the stores only
// read the request token and current user from it, so stub that boundary.
jest.mock('@nextcloud/auth', () => ({
	getRequestToken: () => 'tok',
	getCurrentUser: () => ({ uid: 'admin', displayName: 'Admin' }),
}))

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

/**
 * Open the dialog with an NcDialog that renders its actions, so the buttons can be pressed.
 *
 * @param {object} properties The dialog properties.
 * @return {object} The wrapper.
 */
function openWithActions(properties) {
	navigationStore.setDialog('retentionDecision', properties)
	return mount(RetentionDecisionDialog, {
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><footer><slot name="actions" /></footer></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
				},
				NcLoadingIcon: { template: '<span class="saving" />' },
			},
		},
	})
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

	it('says it takes one publication off the catalogue for a single depublish', async () => {
		const wrapper = open({ decision: 'depublish', ids: ['p1'], publications: [parking] })
		await flushPromises()

		expect(wrapper.text()).toContain('Take 1 publication off the public catalogue today.')
		expect(wrapper.text()).not.toContain('Extra months')
	})

	it('leaves out a selected publication it cannot read', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		const wrapper = open({ decision: 'archive', ids: ['p1', 'p2'], publications: [parking] })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('li').map((li) => li.attributes('data-id'))).toEqual(['p1'])
		expect(wrapper.text()).toContain('Archive 1 publication. It leaves the public catalogue today.')
	})

	it('shows the new dates of an extension, and none for a publication without an end date', async () => {
		const undated = { id: 'p3', title: 'Raadsvoorstel zonder termijn' }
		const wrapper = open({ decision: 'extend', ids: ['p1', 'p3'], publications: [parking, undated] })
		await flushPromises()

		const dates = wrapper.findAll('.retention-decision__dates').map((span) => span.text())
		expect(dates).toHaveLength(2)
		expect(dates[0]).toMatch(/2026.* to .*2027/)
		expect(dates[1]).toBe('')
		expect(wrapper.vm.dateRange(undated)).toBe('')
	})

	it('takes the note and the months as they are typed', async () => {
		const wrapper = open({ decision: 'extend', ids: ['p1'], publications: [parking] })
		await flushPromises()

		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Beroep loopt.')
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', '6')

		expect(wrapper.vm.note).toBe('Beroep loopt.')
		expect(wrapper.vm.monthsValue).toBe(6)
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('saves from its button, shows the spinner while saving, then offers only Close', async () => {
		let answer
		axios.post.mockImplementation(() => new Promise((resolve) => { answer = resolve }))
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = openWithActions({ decision: 'archive', ids: ['p1'], publications: [parking] })
		await flushPromises()

		let buttons = wrapper.findAll('footer button')
		expect(buttons.map((button) => button.text())).toEqual(['Cancel', 'Archive'])
		expect(buttons[1].attributes('disabled')).toBeDefined()

		wrapper.vm.note = 'Termijn verstreken.'
		await wrapper.vm.$nextTick()
		await wrapper.findAll('footer button')[1].trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('footer .saving').exists()).toBe(true)

		answer({ data: {} })
		await flushPromises()

		buttons = wrapper.findAll('footer button')
		expect(buttons.map((button) => button.text())).toEqual(['Close'])
		expect(wrapper.text()).toContain('Archived.')

		await buttons[0].trigger('click')
		expect(setDialog).toHaveBeenCalledWith(false)
	})

	it('clears the dialog when it is closed', async () => {
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = open({ decision: 'archive', ids: ['p1'], publications: [parking] })
		await flushPromises()

		wrapper.vm.onOpenChange(true)
		expect(setDialog).not.toHaveBeenCalledWith(false)

		wrapper.vm.onOpenChange(false)
		expect(setDialog).toHaveBeenCalledWith(false)
	})
})
