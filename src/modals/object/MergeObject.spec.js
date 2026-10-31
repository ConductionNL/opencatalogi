/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `MergeObject.vue` opened from the Publications list: it
 * starts at "Configure merge" with the two handed-over publications, and the
 * merge goes to OpenRegister's merge endpoint for the source's own register
 * and schema.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import MergeObject from './MergeObject.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

const source = {
	id: 'a',
	title: 'Besluit op het Woo-verzoek over windpark Oostpolder',
	summary: 'De gemeente willigt het Woo-verzoek deels in.',
	'@self': { id: 'a', register: 19, schema: 173 },
}
const target = {
	id: 'b',
	title: 'Woo-besluit windpark Oostpolder, concept',
	summary: '',
	'@self': { id: 'b', register: 19, schema: 173 },
}

/**
 * Mount the dialog with Nextcloud's translate as a passthrough.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(MergeObject, {
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (match, key) => vars[key] ?? match),
			},
		},
	})
}

describe('MergeObject from the Publications list', () => {
	beforeEach(() => {
		axios.get.mockResolvedValue({ data: { results: [source, target] } })
		axios.post.mockResolvedValue({ data: { success: true } })
		jest.spyOn(console, 'log').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.clearAllMocks()
		jest.restoreAllMocks()
		navigationStore.setModal(false)
	})

	it('starts at Configure merge with the handed-over source and target', async () => {
		navigationStore.setTransferData({
			mergeSource: source,
			mergeTarget: target,
			startStep: 2,
		})
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.vm.step).toBe(2)
		expect(wrapper.vm.sourceObject).toEqual(source)
		expect(wrapper.vm.selectedTargetObject).toEqual(target)
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/openregister\/api\/objects\/19\/173$/),
			{ params: { _search: '' } },
		)
	})

	it('posts the merge for the source to its own register and schema', async () => {
		navigationStore.setTransferData({
			mergeSource: source,
			mergeTarget: target,
			startStep: 2,
		})
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.performMerge()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toMatch(/\/apps\/openregister\/api\/objects\/19\/173\/a\/merge$/)
		expect(body).toMatchObject({
			target: 'b',
			fileAction: 'transfer',
			relationAction: 'transfer',
		})
		expect(body.object.summary).toBe(source.summary)
		expect(wrapper.vm.step).toBe(3)
	})

	it('closes when nothing was handed over', async () => {
		navigationStore.setModal('mergeObject')
		mountDialog()
		await flushPromises()

		expect(navigationStore.modal).toBe(false)
	})
})
