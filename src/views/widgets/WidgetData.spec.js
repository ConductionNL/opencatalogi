/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the data half of the dashboard widgets: the rows each list
 * widget shapes from the object store, what it fetches, where a row click
 * goes, the most-viewed widget's period and fetch, the retention widget's
 * fetch, and the shared list-widget options.
 */

import CatalogiWidget from './CatalogiWidget.vue'
import MostViewedPublicationsWidget from './MostViewedPublicationsWidget.vue'
import RetentionWidget from './RetentionWidget.vue'
import UnpublishedAttachmentsWidget from './UnpublishedAttachmentsWidget.vue'
import UnpublishedPublicationsWidget from './UnpublishedPublicationsWidget.vue'
import { fetchCatalogStats } from '../../services/usageStats.js'
import { LIST_COLUMNS, LIST_WIDGET_OPTIONS, navigateTo } from './widgetTable.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({ registerIcons: jest.fn() }))
jest.mock('../../store/store.js', () => ({ objectStore: {} }))
jest.mock('../../services/usageStats.js', () => ({
	...jest.requireActual('../../services/usageStats.js'),
	fetchCatalogStats: jest.fn(),
}))

const axios = jest.requireMock('@nextcloud/axios').default
const { objectStore } = jest.requireMock('../../store/store.js')

/**
 * Point the mocked object store at these collections.
 *
 * @param {object} collections Collection name -> results.
 * @return {void}
 */
function useCollections(collections) {
	objectStore.getCollection = jest.fn((name) => ({ results: collections[name] ?? [] }))
	objectStore.fetchCollection = jest.fn().mockResolvedValue()
}

/**
 * A fake instance of a component: its data, computed getters read once, and its methods bound.
 *
 * @param {object} component The component options.
 * @param {object} fields Extra fields.
 * @return {object}
 */
function vm(component, fields = {}) {
	const instance = {
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? vars[key] : m)),
		...(component.data ? component.data.call({}) : {}),
		...fields,
	}
	for (const [name, fn] of Object.entries(component.methods || {})) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

describe('list widget rows', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('lists every catalog by slug, title and summary', () => {
		useCollections({ catalog: [{ slug: 'woo', title: 'Woo', summary: 'Open' }, { slug: 'b', title: 'B' }] })

		expect(CatalogiWidget.computed.items.call(vm(CatalogiWidget))).toEqual([
			{ id: 'woo', mainText: 'Woo', subText: 'Open' },
			{ id: 'b', mainText: 'B', subText: undefined },
		])
	})

	it('lists only the concept attachments', () => {
		useCollections({
			attachment: [
				{ id: 1, title: 'Draft', summary: 's', status: 'Concept' },
				{ id: 2, title: 'Done', status: 'Published' },
			],
		})

		expect(UnpublishedAttachmentsWidget.computed.items.call(vm(UnpublishedAttachmentsWidget))).toEqual([
			{ id: 1, mainText: 'Draft', subText: 's' },
		])
	})

	it('lists only the concept publications and keeps each one on its row', () => {
		const draft = { id: 'p1', title: 'Draft', summary: 's', status: 'Concept' }
		useCollections({ publication: [draft, { id: 'p2', status: 'Published' }] })

		expect(UnpublishedPublicationsWidget.computed.items.call(vm(UnpublishedPublicationsWidget))).toEqual([
			{ id: 'p1', mainText: 'Draft', subText: 's', publication: draft },
		])
	})

	it.each([
		[CatalogiWidget, ['catalog']],
		[UnpublishedAttachmentsWidget, ['attachment']],
		[UnpublishedPublicationsWidget, ['publication', 'catalog']],
	])('%# fetches its collections and clears the loading flag', async (widget, names) => {
		useCollections({})
		const instance = vm(widget)
		let during
		objectStore.fetchCollection.mockImplementation(async () => {
			during = instance.loading
		})

		await instance.fetchData()

		expect(objectStore.fetchCollection.mock.calls.map((call) => call[0])).toEqual(names)
		expect(during).toBe(true)
		expect(instance.loading).toBe(false)
	})

	it('passes the search term on to the catalog fetch', async () => {
		useCollections({})

		await vm(CatalogiWidget).fetchData('woo')

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('catalog', 'woo')
	})
})

describe('list widget row clicks', () => {
	const location = window.location

	beforeEach(() => {
		delete window.location
		window.location = { href: '' }
	})

	afterEach(() => {
		window.location = location
	})

	it('opens a catalog\'s publications', () => {
		vm(CatalogiWidget).onRowClick({ id: 'woo' })

		expect(window.location.href).toBe('/nc/apps/opencatalogi/publications/woo')
	})

	it('opens a publication under its catalog\'s slug', () => {
		useCollections({ catalog: [{ id: 7, slug: 'woo' }] })

		vm(UnpublishedPublicationsWidget).onRowClick({ publication: { id: 'p1', catalog: 7 } })

		expect(window.location.href).toBe('/nc/apps/opencatalogi/publications/woo/p1')
	})

	it.each([
		['no catalog slug can be found', { id: 'p1', catalog: 99 }],
		['the publication has no id', { catalog: 7 }],
	])('does nothing when %s', (_why, publication) => {
		useCollections({ catalog: [{ id: 7, slug: 'woo' }] })

		vm(UnpublishedPublicationsWidget).onRowClick({ publication })

		expect(window.location.href).toBe('')
	})

	it('opens a most-viewed publication by id', () => {
		vm(MostViewedPublicationsWidget).onRowClick({ id: 'p9' })

		expect(window.location.href).toBe('/nc/apps/opencatalogi/publications/p9')
	})

	it('navigateTo ignores an empty url', () => {
		window.location.href = '/here'
		navigateTo('')

		expect(window.location.href).toBe('/here')
	})
})

describe('MostViewedPublicationsWidget', () => {
	beforeEach(() => {
		jest.useFakeTimers().setSystemTime(new Date('2026-11-14T12:00:00Z'))
		fetchCatalogStats.mockReset()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('offers 7, 30 and 90 days and starts at 30', () => {
		const instance = vm(MostViewedPublicationsWidget)

		expect(MostViewedPublicationsWidget.computed.periodOptions.call(instance).map((o) => o.value)).toEqual([7, 30, 90])
		expect(instance.period).toBe(30)
		expect(instance.columns).toBe(LIST_COLUMNS)
	})

	it.each([[7, '2026-11-07'], [30, '2026-10-15'], [90, '2026-08-16']])('a %i day period starts on %s', (period, from) => {
		expect(vm(MostViewedPublicationsWidget, { period }).rangeFrom()).toBe(from)
	})

	it('shapes each entry into a row with its view count', () => {
		const instance = vm(MostViewedPublicationsWidget, { topViewed: [{ publication: 'p1', views: 12 }, { publication: 'p2', views: 'x' }] })

		expect(MostViewedPublicationsWidget.computed.items.call(instance)).toEqual([
			{ id: 'p1', mainText: 'p1', subText: '12 views' },
			{ id: 'p2', mainText: 'p2', subText: '0 views' },
		])
	})

	it('loads the top viewed publications of the catalog from the period start', async () => {
		fetchCatalogStats.mockResolvedValue({ topViewed: [{ publication: 'p1', views: 3 }] })
		const instance = vm(MostViewedPublicationsWidget, { catalogSlug: 'woo', period: 7 })

		await instance.load()

		expect(fetchCatalogStats).toHaveBeenCalledWith('woo', { from: '2026-11-07' })
		expect(instance.topViewed).toEqual([{ publication: 'p1', views: 3 }])
		expect(instance.loading).toBe(false)
	})

	it.each([
		['an answer without a list', () => Promise.resolve({ topViewed: 'none' })],
		['a failed request', () => Promise.reject(new Error('500'))],
	])('shows nothing after %s', async (_why, answer) => {
		fetchCatalogStats.mockImplementation(answer)
		const instance = vm(MostViewedPublicationsWidget, { catalogSlug: 'woo', topViewed: [{ publication: 'old' }] })

		await instance.load()

		expect(instance.topViewed).toEqual([])
		expect(instance.loading).toBe(false)
	})
})

describe('RetentionWidget fetch and click', () => {
	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('merges the queue summary over the zero defaults', async () => {
		axios.get.mockResolvedValue({ data: { reviewRequired: 4 } })
		const instance = vm(RetentionWidget)

		await instance.fetchData()

		expect(axios.get).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/retention/queue')
		expect(instance.summary).toEqual({ expiringSoon: 0, reviewRequired: 4, archived: 0 })
		expect(instance.loading).toBe(false)
	})

	it('keeps the last summary and logs when the queue cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		const instance = vm(RetentionWidget, { summary: { expiringSoon: 1, reviewRequired: 0, archived: 0 } })

		await instance.fetchData()

		expect(instance.summary).toEqual({ expiringSoon: 1, reviewRequired: 0, archived: 0 })
		expect(error).toHaveBeenCalled()
		expect(instance.loading).toBe(false)
	})

	it('opens the retention review queue in the same tab', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)

		vm(RetentionWidget).onEntryClick()

		expect(open).toHaveBeenCalledWith('/nc/apps/opencatalogi/retention', '_self')
	})
})

describe('LIST_WIDGET_OPTIONS', () => {
	it('requires a title, starts idle with the list columns and fetches on mount', () => {
		const fetchData = jest.fn()

		LIST_WIDGET_OPTIONS.mounted.call({ fetchData })

		expect(LIST_WIDGET_OPTIONS.props.title).toEqual({ type: String, required: true })
		expect(LIST_WIDGET_OPTIONS.data()).toEqual({ loading: false, columns: LIST_COLUMNS })
		expect(fetchData).toHaveBeenCalledTimes(1)
	})
})
