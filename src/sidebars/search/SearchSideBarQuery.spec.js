/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the URL sync of `SearchSideBar.vue`: on mount the route
 * query is applied to the search store (term, sort, view mode, filters), and
 * a store change writes the query back only when it differs from the route.
 */

import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text, translate: (app, text) => text }))

jest.mock('@nextcloud/vue', () => {
	const slots = { template: '<div><slot name="icon" /><slot /></div>' }
	return {
		NcAppSidebar: slots,
		NcAppSidebarTab: slots,
		NcButton: slots,
		NcNoteCard: slots,
		NcSelect: { template: '<div />' },
	}
})

jest.mock('../../components/FacetComponent.vue', () => ({ template: '<div />' }))

const calls = []
const record = (name) => jest.fn((...args) => calls.push([name, ...args]))

const mockStore = reactive({
	getSearchTerm: '',
	getOrdering: {},
	getViewMode: '',
	getFilters: {},
	currentFacets: {},
	setSearchTerm: null,
	clearOrdering: null,
	setOrdering: null,
	setViewMode: null,
	clearAllFilters: null,
	setFilters: null,
})

jest.mock('../../store/modules/search.ts', () => ({ useSearchStore: () => mockStore }))

/**
 * Reset the fake store and the call log.
 *
 * @return {void}
 */
function resetStore() {
	calls.length = 0
	Object.assign(mockStore, {
		getSearchTerm: '',
		getOrdering: {},
		getViewMode: '',
		getFilters: {},
		setSearchTerm: record('setSearchTerm'),
		clearOrdering: record('clearOrdering'),
		setOrdering: record('setOrdering'),
		setViewMode: record('setViewMode'),
		clearAllFilters: record('clearAllFilters'),
		setFilters: record('setFilters'),
		loadInitialResults: jest.fn(() => Promise.resolve()),
		searchPublications: jest.fn(),
		discoverFacetableFields: jest.fn(),
		clearFilter: jest.fn(),
	})
}

/**
 * Mount the sidebar on the /search route with `query`.
 *
 * @param {object} query The route query.
 * @return {{wrapper: object, router: object}} The wrapper and the router mock.
 */
function mountOn(query) {
	const router = { replace: jest.fn() }
	const wrapper = mount(SearchSideBar, {
		global: {
			mocks: { t: (app, text) => text },
			// The component reads $route and $router from its proxy in
			// <script setup>, so they go on the app's global properties.
			plugins: [{
				install(app) {
					app.config.globalProperties.$route = { path: '/search', query, fullPath: '/search' }
					app.config.globalProperties.$router = router
				},
			}],
		},
	})
	return { wrapper, router }
}

describe('SearchSideBar route query to store', () => {
	beforeEach(resetStore)

	it('applies an empty query as an empty term and no filters', () => {
		mountOn({})

		expect(calls).toEqual([['setSearchTerm', ''], ['clearAllFilters']])
	})

	it('applies term, sort, view and filters in order', () => {
		mountOn({ q: 'abc', sort: 'title:DESC', view: 'cards', type: 'x', empty: '', none: null, page: 2 })

		expect(calls).toEqual([
			['setSearchTerm', 'abc'],
			['clearOrdering'],
			['setOrdering', 'title', 'DESC'],
			['setViewMode', 'cards'],
			['clearAllFilters'],
			['setFilters', { type: 'x', page: '2' }],
		])
	})

	it.each([
		[{ sort: 'title:asc' }, [['setSearchTerm', ''], ['clearOrdering'], ['setOrdering', 'title', 'ASC'], ['clearAllFilters']]],
		[{ sort: 'title' }, [['setSearchTerm', ''], ['clearOrdering'], ['clearAllFilters']]],
		[{ sort: ':DESC' }, [['setSearchTerm', ''], ['clearOrdering'], ['clearAllFilters']]],
		[{ view: 'table' }, [['setSearchTerm', ''], ['setViewMode', 'table'], ['clearAllFilters']]],
		[{ view: 'list' }, [['setSearchTerm', ''], ['clearAllFilters']]],
		[{ q: ['a', 'b'] }, [['setSearchTerm', ''], ['clearAllFilters']]],
	])('applies %j', (query, expected) => {
		mountOn(query)

		expect(calls).toEqual(expected)
	})
})

describe('SearchSideBar store to route query', () => {
	beforeEach(() => {
		resetStore()
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	/**
	 * Mount on `routeQuery`, then change the store and let the debounce run.
	 *
	 * @param {object} routeQuery The current route query.
	 * @param {object} state The store state to switch to.
	 * @return {Promise<object>} The router mock.
	 */
	async function writeAfter(routeQuery, state) {
		const { wrapper, router } = mountOn(routeQuery)
		Object.assign(mockStore, state)
		await wrapper.vm.$nextTick()
		jest.advanceTimersByTime(400)
		return router
	}

	it('leaves the route alone when the query is the same', async () => {
		const router = await writeAfter({ q: 'abc', type: 'x' }, { getSearchTerm: 'abc', getFilters: { type: 'x' } })

		expect(router.replace).not.toHaveBeenCalled()
	})

	it('compares values as strings', async () => {
		const router = await writeAfter({ q: 'abc', page: 2 }, { getSearchTerm: 'abc', getFilters: { page: '2' } })

		expect(router.replace).not.toHaveBeenCalled()
	})

	it('writes the query when a value differs', async () => {
		const router = await writeAfter({ q: 'abc' }, { getSearchTerm: 'abd' })

		expect(router.replace).toHaveBeenCalledWith({ path: '/search', query: { q: 'abd' } })
	})

	it('writes the query when the route has an extra key', async () => {
		const router = await writeAfter({ q: 'abc', type: 'x' }, { getSearchTerm: 'abc' })

		expect(router.replace).toHaveBeenCalledWith({ path: '/search', query: { q: 'abc' } })
	})

	it('writes sort, view and joined filter values', async () => {
		const router = await writeAfter({}, {
			getSearchTerm: '',
			getOrdering: { title: 'ASC' },
			getViewMode: 'cards',
			getFilters: { tags: ['a', 'b'] },
		})

		expect(router.replace).toHaveBeenCalledWith({
			path: '/search',
			query: { sort: 'title:ASC', view: 'cards', tags: 'a,b' },
		})
	})
})
