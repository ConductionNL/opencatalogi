/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CatalogStaffAccessFields.vue`: the switch and the two
 * group pickers edit one `staffAccess` value.
 */

import { flushPromises, mount } from '@vue/test-utils'
import CatalogStaffAccessFields from './CatalogStaffAccessFields.vue'

jest.mock('../services/nextcloudGroups.js', () => ({
	getNextcloudGroups: () =>
		Promise.resolve([
			{ label: 'Griffie', value: 'griffie' },
			{ label: 'Woo-team', value: 'woo-team' },
		]),
}))

/**
 * @param {object|null} modelValue The staff access.
 * @return {object} The wrapper.
 */
async function mountFields(modelValue) {
	const wrapper = mount(CatalogStaffAccessFields, { props: { modelValue } })
	await flushPromises()
	return wrapper
}

describe('CatalogStaffAccessFields', () => {
	it('shows no group pickers while unrestricted', async () => {
		const wrapper = await mountFields(null)

		expect(wrapper.findAllComponents({ name: 'NcSelect' })).toHaveLength(0)
	})

	it('restricts with the switch', async () => {
		const wrapper = await mountFields(null)

		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)

		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual({
			restricted: true,
			readGroups: [],
			editGroups: [],
		})
	})

	it('picks the edit groups by id and labels them by name', async () => {
		const wrapper = await mountFields({
			restricted: true,
			readGroups: [],
			editGroups: ['griffie'],
		})
		const [, edit] = wrapper.findAllComponents({ name: 'NcSelect' })

		expect(edit.props('modelValue')).toEqual([
			{ id: 'griffie', label: 'Griffie' },
		])
		edit.vm.$emit('update:modelValue', [
			{ id: 'griffie', label: 'Griffie' },
			{ id: 'woo-team', label: 'Woo-team' },
		])

		expect(wrapper.emitted('update:modelValue')[0][0].editGroups).toEqual([
			'griffie',
			'woo-team',
		])
	})
})
