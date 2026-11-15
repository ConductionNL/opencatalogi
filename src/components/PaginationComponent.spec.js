/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PaginationComponent.vue`: when it shows, the page
 * buttons it offers and the page and page-size changes it emits.
 */

import { mount } from '@vue/test-utils'
import PaginationComponent from './PaginationComponent.vue'

/**
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function pager(props) {
	return mount(PaginationComponent, {
		props,
		global: { mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) } },
	})
}

/**
 * @param {object} wrapper The pager.
 * @param {string} label The button text.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === label)
}

describe('PaginationComponent', () => {
	it('hides for one page with few items', () => {
		expect(pager({ totalPages: 1, totalItems: 5 }).find('.viewPagination').exists()).toBe(false)
	})

	it('shows the page size alone for one page with more items than the minimum', () => {
		const wrapper = pager({ totalPages: 1, totalItems: 11 })

		expect(wrapper.find('.viewPageInfo').text()).toBe('Page 1 of 1')
		expect(wrapper.find('.viewPaginationNav').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual({ value: 20, label: '20' })
	})

	it('disables first and previous on the first page, next and last on the last', () => {
		const first = pager({ currentPage: 1, totalPages: 3 })
		expect(button(first, 'First').attributes('disabled')).toBeDefined()
		expect(button(first, 'Previous').attributes('disabled')).toBeDefined()
		expect(button(first, 'Next').attributes('disabled')).toBeUndefined()

		const last = pager({ currentPage: 3, totalPages: 3 })
		expect(button(last, 'Next').attributes('disabled')).toBeDefined()
		expect(button(last, 'Last').attributes('disabled')).toBeDefined()
	})

	it('marks the current page and elides far pages', () => {
		const wrapper = pager({ currentPage: 5, totalPages: 10 })
		const current = button(wrapper, '5')

		expect(current.props('variant')).toBe('primary')
		expect(current.attributes('disabled')).toBeDefined()
		expect(button(wrapper, '4').props('variant')).toBe('secondary')
		expect(wrapper.findAll('.viewPaginationEllipsis').length).toBeGreaterThan(0)
	})

	it('emits the page a button names', async () => {
		const wrapper = pager({ currentPage: 2, totalPages: 4 })

		await button(wrapper, 'First').trigger('click')
		await button(wrapper, 'Previous').trigger('click')
		await button(wrapper, 'Next').trigger('click')
		await button(wrapper, 'Last').trigger('click')
		await button(wrapper, '3').trigger('click')

		expect(wrapper.emitted('page-changed')).toEqual([[1], [1], [3], [4], [3]])
	})

	it('emits no change for the current page or one out of range', () => {
		const wrapper = pager({ currentPage: 2, totalPages: 4 })

		wrapper.vm.changePage(2)
		wrapper.vm.changePage(0)
		wrapper.vm.changePage(5)

		expect(wrapper.emitted('page-changed')).toBeUndefined()
	})

	it('emits a new page size, not the current one', () => {
		const wrapper = pager({ currentPage: 1, totalPages: 2, currentPageSize: 50 })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('modelValue')).toEqual({ value: 50, label: '50' })
		select.vm.$emit('option:selected', { value: 50, label: '50' })
		select.vm.$emit('option:selected', { value: 100, label: '100' })

		expect(wrapper.emitted('page-size-changed')).toEqual([[100]])
	})

	it('falls back to the second option for an unknown page size', () => {
		const wrapper = pager({ totalPages: 2, currentPageSize: 7 })

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual({ value: 20, label: '20' })
	})
})
