/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The instance's default DCAT licence is picked from the list and saved on
 * choice (REQ-DCC-001).
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import DcatDefaultLicence from './DcatDefaultLicence.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), put: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (app, text) => text,
}))

const CC0 = 'http://publications.europa.eu/resource/authority/licence/CC0'
const BY = 'http://publications.europa.eu/resource/authority/licence/CC_BY_4_0'

/**
 * Mount the block over a stored default and let its read settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountBlock() {
	const wrapper = mount(DcatDefaultLicence, {
		global: {
			stubs: {
				LicencePicker: {
					name: 'LicencePicker',
					props: ['modelValue', 'inputLabel', 'disabled'],
					emits: ['update:modelValue'],
					template: '<div class="picker-stub" />',
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.put.mockReset()
	axios.get.mockResolvedValue({ data: { configuration: { dcat_default_license: CC0 } } })
})

describe('DcatDefaultLicence', () => {
	it('shows the stored default in the picker', async () => {
		const wrapper = await mountBlock()

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/settings')
		expect(wrapper.findComponent({ name: 'LicencePicker' }).props('modelValue')).toBe(CC0)
	})

	it('saves a chosen licence and says so', async () => {
		axios.put.mockResolvedValue({ data: { dcat_default_license: BY } })
		const wrapper = await mountBlock()

		wrapper.findComponent({ name: 'LicencePicker' }).vm.$emit('update:modelValue', BY)
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/opencatalogi/api/settings', { dcat_default_license: BY })
		expect(wrapper.findComponent({ name: 'LicencePicker' }).props('modelValue')).toBe(BY)
		expect(wrapper.find('[data-testid="dcat-default-licence-message"]').text()).toBe('Default licence saved.')
	})

	it('keeps the stored default and shows the refusal when the save fails', async () => {
		axios.put.mockRejectedValue({ response: { data: { error: 'Choose a licence from the EU licences authority list.' } } })
		const wrapper = await mountBlock()

		wrapper.findComponent({ name: 'LicencePicker' }).vm.$emit('update:modelValue', BY)
		await flushPromises()

		expect(wrapper.findComponent({ name: 'LicencePicker' }).props('modelValue')).toBe(CC0)
		expect(wrapper.find('[data-testid="dcat-default-licence-message"]').text()).toBe('Choose a licence from the EU licences authority list.')
	})
})
