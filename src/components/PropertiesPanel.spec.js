/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PropertiesPanel.vue`: what it hands the library's
 * properties tab, the drop button it adds per row, and the events it passes on.
 */

import { mount } from '@vue/test-utils'
import { h } from 'vue'
import PropertiesPanel from './PropertiesPanel.vue'

const CnPropertiesTab = {
	name: 'CnPropertiesTab',
	props: ['schema', 'item', 'formData', 'selectedProperty', 'propertyOverrides', 'isNew'],
	emits: ['update:selectedProperty', 'update:propertyValue'],
	render() {
		return h('div', { class: 'cn-properties-tab-stub' }, [
			h('div', { class: 'row-a' }, this.$slots['row-actions']?.({ propertyKey: 'a', resolvedValue: 1 })),
			h('div', { class: 'row-b' }, this.$slots['row-actions']?.({ propertyKey: 'b', resolvedValue: null })),
		])
	},
}

/**
 * @param {object} props Props on top of the defaults.
 * @return {object} The wrapper.
 */
function panel(props = {}) {
	return mount(PropertiesPanel, {
		props: {
			formData: { a: 1 },
			canDropProperty: (key, value) => value !== null,
			getDropPropertyTooltip: (key) => `Drop ${key}`,
			...props,
		},
		global: { stubs: { CnPropertiesTab } },
	})
}

describe('PropertiesPanel', () => {
	it('hands the tab the schema, an empty item by default and the form', () => {
		const tab = panel({ resolvedSchema: { title: 'S' }, isNew: true, selectedProperty: 'a' }).findComponent({ name: 'CnPropertiesTab' })

		expect(tab.props('schema')).toEqual({ title: 'S' })
		expect(tab.props('item')).toEqual({})
		expect(tab.props('formData')).toEqual({ a: 1 })
		expect(tab.props('selectedProperty')).toBe('a')
		expect(tab.props('propertyOverrides')).toEqual({})
		expect(tab.props('isNew')).toBe(true)
	})

	it('hands the tab the current object when there is one', () => {
		expect(panel({ currentObject: { id: 9 } }).findComponent({ name: 'CnPropertiesTab' }).props('item')).toEqual({ id: 9 })
	})

	it('adds a labelled drop button only to rows that may drop, and emits the property', async () => {
		const wrapper = panel()

		expect(wrapper.find('.row-b button').exists()).toBe(false)
		const drop = wrapper.findComponent({ name: 'NcButton' })
		expect(drop.attributes('aria-label')).toBe('Drop a')
		await wrapper.find('.row-a button').trigger('click')

		expect(wrapper.emitted('drop-property')).toEqual([['a']])
	})

	it('passes the tab selection and value changes on in kebab case', () => {
		const wrapper = panel()
		const tab = wrapper.findComponent({ name: 'CnPropertiesTab' })

		tab.vm.$emit('update:selectedProperty', 'a')
		tab.vm.$emit('update:propertyValue', { key: 'a', value: 2 })

		expect(wrapper.emitted('update:selected-property')).toEqual([['a']])
		expect(wrapper.emitted('update:property-value')).toEqual([[{ key: 'a', value: 2 }]])
	})
})
