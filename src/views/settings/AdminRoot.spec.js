/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings bundle's root renders the settings page and nothing
 * else (admin-settings 11.2).
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-11
 */

import { mount } from '@vue/test-utils'
import AdminRoot from './AdminRoot.vue'

jest.mock('./Settings.vue', () => ({
	name: 'AdminSettings',
	template: '<section class="admin-settings-page" />',
}))

describe('AdminRoot', () => {
	it('renders the settings page', () => {
		const wrapper = mount(AdminRoot)

		expect(wrapper.findComponent({ name: 'AdminSettings' }).exists()).toBe(true)
		expect(wrapper.find('.admin-settings-page').exists()).toBe(true)
	})

	it('registers the settings page itself, not through a global component', () => {
		expect(Object.keys(AdminRoot.components)).toEqual(['Settings'])
	})
})
