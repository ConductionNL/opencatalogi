/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The licence picker offers the list only, stores the IRI and names a stored
 * value that is not on the list (REQ-DCC-001).
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

import { flushPromises, mount } from '@vue/test-utils'
import LicencePicker from './LicencePicker.vue'
import { fetchLicences } from '../services/licences.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../services/licences.js', () => {
	const actual = jest.requireActual('../services/licences.js')
	return { ...actual, fetchLicences: jest.fn() }
})
jest.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]),
}))

const CC0 = 'http://publications.europa.eu/resource/authority/licence/CC0'
const ROWS = [{ code: 'CC0', iri: CC0, label: 'Creative Commons Zero', labelNl: 'Creative Commons Zero' }]

/**
 * Mount the picker with a stored value and let the list read settle.
 *
 * @param {string} modelValue The stored licence.
 * @return {Promise<object>} The wrapper.
 */
async function mountPicker(modelValue = '') {
	const wrapper = mount(LicencePicker, {
		props: { modelValue },
		global: {
			stubs: {
				NcSelect: {
					name: 'NcSelect',
					props: ['modelValue', 'options', 'inputLabel', 'placeholder', 'disabled', 'loading', 'clearable'],
					emits: ['update:modelValue'],
					template: '<div class="select-stub" />',
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	fetchLicences.mockReset()
	fetchLicences.mockResolvedValue(ROWS)
})

describe('LicencePicker', () => {
	it('offers the list and shows the stored IRI as its option', async () => {
		const select = (await mountPicker(CC0)).findComponent({ name: 'NcSelect' })

		expect(select.props('options')).toEqual([{ id: CC0, code: 'CC0', label: 'Creative Commons Zero' }])
		expect(select.props('modelValue')).toEqual({ id: CC0, code: 'CC0', label: 'Creative Commons Zero' })
		expect(select.props('inputLabel')).toBe('Licence')
	})

	it('emits the IRI of a chosen licence, and an empty value when cleared', async () => {
		const wrapper = await mountPicker()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('update:modelValue', { id: CC0, code: 'CC0', label: 'Creative Commons Zero' })
		select.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:modelValue')).toEqual([[CC0], ['']])
	})

	it('names a stored licence that is not on the list', async () => {
		const wrapper = await mountPicker('vrij te gebruiken')

		expect(wrapper.find('[data-testid="licence-picker-unlisted"]').text()).toContain('"vrij te gebruiken" is not on the EU licences list')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBeNull()
	})

	it('says so when the list cannot be read, and does not call the value unlisted', async () => {
		fetchLicences.mockRejectedValue(new Error('offline'))
		const wrapper = await mountPicker('vrij te gebruiken')

		expect(wrapper.find('[data-testid="licence-picker-error"]').text()).toBe('The licence list could not be loaded.')
		expect(wrapper.find('[data-testid="licence-picker-unlisted"]').exists()).toBe(false)
	})
})
