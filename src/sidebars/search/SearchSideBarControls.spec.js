/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the controls of `SearchSideBar.vue`, driven through its
 * template: the debounced search box and its clear button, the sort choice,
 * the view mode, the active filters, the facet filter dropdowns and the facet
 * refresh.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text, translate: (app, text) => text }))

jest.mock('@nextcloud/vue', () => ({
	NcAppSidebar: {
		props: { open: Boolean },
		emits: ['update:open'],
		template: '<div class="sidebar" :data-open="open"><button class="toggle" @click="$emit(\'update:open\', !open)" /><slot /></div>',
	},
	NcAppSidebarTab: { template: '<div><slot name="icon" /><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcButton: {
		props: { ariaLabel: { type: String, default: '' }, disabled: Boolean, variant: { type: String, default: '' } },
		emits: ['click'],
		template: '<button :aria-label="ariaLabel" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: { modelValue: { type: Object, default: null }, options: { type: Array, default: () => [] }, ariaLabelCombobox: { type: String, default: '' } },
		emits: ['update:modelValue', 'option:selected', 'option:deselected'],
		template: '<div class="select" :data-combobox="ariaLabelCombobox" :data-selected="modelValue ? modelValue.label : \'\'" :data-options="options.map((o) => o.label).join(\'|\')" />',
	},
}))

jest.mock('../../components/FacetComponent.vue', () => ({ template: '<div />' }))

const mockStore = reactive({})

jest.mock('../../store/modules/search.ts', () => ({ useSearchStore: () => mockStore }))

/**
 * Reset the fake store.
 *
 * @param {object} state Getter values to start from.
 * @return {void}
 */
function resetStore(state = {}) {
	for (const key of Object.keys(mockStore)) delete mockStore[key]
	Object.assign(mockStore, {
		getSearchTerm: '',
		getOrdering: {},
		getViewMode: '',
		getFilters: {},
		currentFacets: {},
		getActiveFacets: {},
		hasFacetResults: false,
		isFacetsLoading: false,
		setSearchTerm: jest.fn(),
		clearOrdering: jest.fn(),
		setOrdering: jest.fn(),
		setViewMode: jest.fn(),
		clearAllFilters: jest.fn(),
		setFilters: jest.fn(),
		clearFilter: jest.fn(),
		loadInitialResults: jest.fn(() => Promise.resolve()),
		searchPublications: jest.fn(),
		discoverFacetableFields: jest.fn(() => Promise.resolve()),
		...state,
	})
}

/**
 * Mount the sidebar outside the /search route, so it never writes the URL,
 * and forget the calls the mount itself made.
 *
 * @param {object} props The props.
 * @param {boolean} forget Whether to forget the calls the mount made.
 * @return {Promise<object>} The wrapper.
 */
async function mountSidebar(props = {}, forget = true) {
	const wrapper = mount(SearchSideBar, {
		props,
		global: {
			mocks: { t: (app, text) => text },
			plugins: [{
				install(app) {
					app.config.globalProperties.$route = { path: '/elsewhere', query: {}, fullPath: '/elsewhere' }
					app.config.globalProperties.$router = { replace: jest.fn() }
				},
			}],
		},
	})
	await flushPromises()
	jest.runOnlyPendingTimers()
	if (forget) jest.clearAllMocks()
	return wrapper
}

/**
 * The button with an aria-label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The aria-label.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.find(`button[aria-label="${label}"]`)
}

beforeEach(() => {
	jest.useFakeTimers()
	resetStore()
})

afterEach(() => {
	jest.useRealTimers()
})

describe('SearchSideBar search box', () => {
	it('searches 800 ms after the last keystroke, once', async () => {
		const wrapper = await mountSidebar()
		const input = wrapper.find('input[type="search"]')

		await input.setValue('beslu')
		jest.advanceTimersByTime(500)
		await input.setValue('besluit')
		jest.advanceTimersByTime(799)
		expect(mockStore.setSearchTerm).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(mockStore.setSearchTerm).toHaveBeenCalledTimes(1)
		expect(mockStore.setSearchTerm).toHaveBeenCalledWith('besluit')
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(1)
	})

	it('offers a clear button only while there is a term, and clearing searches at once', async () => {
		const wrapper = await mountSidebar()
		expect(button(wrapper, 'Clear search').exists()).toBe(false)

		await wrapper.find('input[type="search"]').setValue('besluit')
		await button(wrapper, 'Clear search').trigger('click')

		expect(mockStore.setSearchTerm).toHaveBeenCalledWith('')
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(1)
		expect(wrapper.find('input[type="search"]').element.value).toBe('')
	})

	it('shows a term the store received elsewhere', async () => {
		const wrapper = await mountSidebar()

		mockStore.getSearchTerm = 'raad'
		await flushPromises()

		expect(wrapper.find('input[type="search"]').element.value).toBe('raad')
	})
})

describe('SearchSideBar sorting and view', () => {
	it('shows the current ordering as the chosen sort option', async () => {
		resetStore({ getOrdering: { '@self.updated': 'DESC' } })
		const wrapper = await mountSidebar()

		expect(wrapper.find('.select[data-combobox="Sort by"]').attributes('data-selected')).toBe('Recently modified')
	})

	it('offers relevance and the six field orderings', async () => {
		const wrapper = await mountSidebar()

		expect(wrapper.find('.select[data-combobox="Sort by"]').attributes('data-options')).toBe(
			'Relevance|Title A-Z|Title Z-A|Recently modified|Oldest first|Recently created|Oldest created',
		)
	})

	it('orders by the chosen field, or by relevance without one, and searches again', async () => {
		const wrapper = await mountSidebar()
		const sort = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('ariaLabelCombobox') === 'Sort by')

		sort.vm.$emit('update:modelValue', { field: 'title', direction: 'DESC' })
		expect(mockStore.clearOrdering).toHaveBeenCalledTimes(1)
		expect(mockStore.setOrdering).toHaveBeenCalledWith('title', 'DESC')
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(1)

		sort.vm.$emit('update:modelValue', { field: null, direction: null })
		expect(mockStore.clearOrdering).toHaveBeenCalledTimes(2)
		expect(mockStore.setOrdering).toHaveBeenCalledTimes(1)
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(2)
	})

	it('switches between cards and table and marks the current one', async () => {
		resetStore({ getViewMode: 'table' })
		const wrapper = await mountSidebar()

		expect(button(wrapper, 'Table view').attributes('data-variant')).toBe('primary')
		expect(button(wrapper, 'Card view').attributes('data-variant')).toBe('tertiary')

		await button(wrapper, 'Card view').trigger('click')
		await button(wrapper, 'Table view').trigger('click')
		expect(mockStore.setViewMode.mock.calls).toEqual([['cards'], ['table']])
	})
})

describe('SearchSideBar active filters', () => {
	it('lists each filter with a readable key and value', async () => {
		resetStore({ getFilters: { '@self.organisation_id': ['a', 'b'], 'publication-type': 7 } })
		const wrapper = await mountSidebar()

		const keys = wrapper.findAll('.active-filter-item .filter-key').map((item) => item.text())
		const values = wrapper.findAll('.active-filter-item .filter-value').map((item) => item.text())
		expect(keys).toEqual(['Organisation Id:', 'Publication Type:'])
		expect(values).toEqual(['a, b', '7'])
	})

	it('removes one filter or all of them', async () => {
		resetStore({ getFilters: { status: 'open' } })
		const wrapper = await mountSidebar()

		await button(wrapper, 'Remove filter').trigger('click')
		expect(mockStore.clearFilter).toHaveBeenCalledWith('status')

		await button(wrapper, 'Clear all filters').trigger('click')
		expect(mockStore.clearAllFilters).toHaveBeenCalledTimes(1)
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(1)
	})

	it('shows no active filters section without filters', async () => {
		const wrapper = await mountSidebar()

		expect(wrapper.find('.active-filters').exists()).toBe(false)
	})
})

describe('SearchSideBar facet filters', () => {
	const facets = {
		'@self.schema': { buckets: [{ _id: 's1', count: 3 }, { key: 's2', doc_count: 1 }] },
		type: [{ value: 'besluit', results: 2 }, { value: 'advies' }],
		empty: null,
	}

	it('shows a dropdown per facet with its buckets and counts', async () => {
		resetStore({ hasFacetResults: true, getActiveFacets: { type: true }, currentFacets: facets })
		const wrapper = await mountSidebar()

		const labels = wrapper.findAll('.filter-result-item label').map((l) => l.text())
		expect(labels).toEqual(['Schema', 'Type', 'Empty'])
		const options = wrapper.findAll('.filter-result-item .select').map((s) => s.attributes('data-options'))
		expect(options).toEqual(['s1 (3)|s2 (1)', 'besluit (2)|advies (0)', ''])
	})

	it('marks the filtered value as chosen', async () => {
		resetStore({ hasFacetResults: true, getActiveFacets: { type: true }, currentFacets: facets, getFilters: { type: 'besluit' } })
		const wrapper = await mountSidebar()

		const chosen = wrapper.findAll('.filter-result-item .select').map((s) => s.attributes('data-selected'))
		expect(chosen).toEqual(['', 'besluit (2)', ''])
	})

	it('filters on a chosen bucket and clears on a deselect, searching each time', async () => {
		resetStore({ hasFacetResults: true, getActiveFacets: { type: true }, currentFacets: facets })
		const wrapper = await mountSidebar()
		const typeSelect = wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.props('ariaLabelCombobox') === 'Filter results')[1]

		typeSelect.vm.$emit('option:selected', { value: 'besluit' })
		typeSelect.vm.$emit('option:deselected')

		expect(mockStore.setFilters).toHaveBeenCalledWith({ type: 'besluit' })
		expect(mockStore.clearFilter).toHaveBeenCalledWith('type')
		expect(mockStore.searchPublications).toHaveBeenCalledTimes(2)
	})

	it('hides the facet filters until there are facet results for active facets', async () => {
		resetStore({ hasFacetResults: true, getActiveFacets: {}, currentFacets: facets })
		const wrapper = await mountSidebar()

		expect(wrapper.find('.filter-results-section').exists()).toBe(false)
	})

	it('rediscovers the facetable fields on refresh, and disables refresh while loading', async () => {
		const wrapper = await mountSidebar()

		await button(wrapper, 'Refresh facets').trigger('click')
		expect(mockStore.discoverFacetableFields).toHaveBeenCalledTimes(1)

		mockStore.isFacetsLoading = true
		await flushPromises()
		expect(button(wrapper, 'Refresh facets').attributes('disabled')).toBeDefined()
	})
})

describe('SearchSideBar open state and start-up', () => {
	it('passes its open prop on and reports a toggle', async () => {
		const wrapper = await mountSidebar({ open: true })

		expect(wrapper.find('.sidebar').attributes('data-open')).toBe('true')
		await wrapper.find('.toggle').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('logs a failed first load instead of throwing', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		resetStore({ loadInitialResults: jest.fn(() => Promise.reject(new Error('offline'))) })

		await mountSidebar({}, false)

		expect(error).toHaveBeenCalledWith('SearchSideBar: Failed to load initial results:', new Error('offline'))
		error.mockRestore()
	})
})
