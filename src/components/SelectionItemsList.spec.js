/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `SelectionItemsList.vue`: the title and count, each
 * item's name, subtitle, error and disabled state, the remove button and
 * the empty state.
 */

import { mount } from '@vue/test-utils'
import SelectionItemsList from './SelectionItemsList.vue'

const ITEMS = [
	{ id: 1, name: 'Een', kind: 'Besluit' },
	{ id: 2, name: 'Twee', kind: 'Nota', error: 'Te groot' },
	{ id: 3, name: 'Drie', kind: 'Brief', locked: true },
]

/**
 * @param {object} props Props on top of the defaults.
 * @return {object} The wrapper.
 */
function list(props = {}) {
	return mount(SelectionItemsList, {
		props: {
			items: ITEMS,
			title: 'Geselecteerd',
			emptyTitle: 'Niets geselecteerd',
			emptyDescription: 'Kies eerst iets',
			showRemove: true,
			itemName: (item) => item.name,
			itemSubtitle: (item) => item.kind,
			itemError: (item) => item.error || null,
			...props,
		},
		global: {
			mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) },
			stubs: { TransitionGroup: false },
		},
	})
}

describe('SelectionItemsList', () => {
	it('titles the list with its count, unless the title is hidden', () => {
		expect(list().find('h4').text()).toBe('Geselecteerd (3)')
		expect(list({ hideTitle: true }).find('h4').exists()).toBe(false)
	})

	it('shows each item with its name, subtitle and error', () => {
		const rows = list().findAll('.selected-object-item')

		expect(rows.map((row) => row.find('strong').text())).toEqual(['Een', 'Twee', 'Drie'])
		expect(rows.map((row) => row.find('.object-schema').text())).toEqual(['Besluit', 'Nota', 'Brief'])
		expect(rows[1].classes()).toContain('has-error')
		expect(rows[1].find('.object-error').text()).toBe('Te groot')
		expect(rows[0].find('.object-error').exists()).toBe(false)
	})

	it('marks a disabled item and gives its reason as the title', () => {
		const rows = list({
			itemDisabled: (item) => item.locked === true,
			itemDisabledReason: (item) => (item.locked ? 'Vergrendeld' : null),
		}).findAll('.selected-object-item')

		expect(rows[2].classes()).toContain('is-disabled')
		expect(rows[2].attributes('title')).toBe('Vergrendeld')
		expect(rows[0].classes()).not.toContain('is-disabled')
		expect(rows[0].attributes('title')).toBeUndefined()
	})

	it('removes an item with its labelled button', async () => {
		const wrapper = list()
		const buttons = wrapper.findAllComponents({ name: 'NcButton' })

		expect(buttons).toHaveLength(3)
		expect(buttons[1].attributes('aria-label')).toBe('Remove Twee')
		await buttons[1].trigger('click')
		expect(wrapper.emitted('remove')).toEqual([[ITEMS[1]]])
	})

	it('has no remove buttons when removing is off', () => {
		expect(list({ showRemove: false }).findAllComponents({ name: 'NcButton' })).toHaveLength(0)
	})

	it('shows the empty state without items', () => {
		const wrapper = list({ items: [] })
		const empty = wrapper.findComponent({ name: 'NcEmptyContent' })

		expect(empty.exists()).toBe(true)
		expect(empty.props('name')).toBe('Niets geselecteerd')
		expect(wrapper.find('.selected-objects-list').exists()).toBe(false)
	})
})
