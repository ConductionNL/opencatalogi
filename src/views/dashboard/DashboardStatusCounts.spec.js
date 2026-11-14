/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test for the publication KPI counts of `Dashboard.vue`: the counts come
 * from OpenRegister's aggregation when it answers, and from the fetched page
 * of publications when it does not.
 */

import Dashboard from './Dashboard.vue'
import { fetchPublicationStatusCounts } from '../../services/publicationStatusCounts.js'

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	buildHeaders: () => ({ requesttoken: 'token-1' }),
	registerIcons: () => {},
}))
jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => ({ publication_register: 'reg-1', publication_schema: 'sch-1' })[key] ?? fallback,
}))
jest.mock('../../services/publicationStatusCounts.js', () => ({ fetchPublicationStatusCounts: jest.fn() }))
jest.mock('../../services/usageStats.js', () => ({ countingStartNote: () => null, fetchInstanceSeries: async () => [] }))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {} }))

// Nextcloud provides `t` globally; here it returns the source text.
global.t = (app, text) => text

/**
 * The KPIs for a state.
 *
 * @param {object} state The component state.
 * @return {object} The KPIs.
 */
function kpis(state) {
	return Dashboard.computed.kpis.call({
		catalogs: [],
		allPublications: ['a', 'b', 'c', 'd'],
		conceptPublications: ['a'],
		publishedPublications: ['b', 'c'],
		depublishedPublications: ['d'],
		publicationTotal: 0,
		statusCounts: null,
		...state,
	})
}

beforeEach(() => {
	fetchPublicationStatusCounts.mockReset()
	jest.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => console.warn.mockRestore())

describe('Dashboard publication KPIs', () => {
	it('reads every count from the aggregation totals', () => {
		expect(kpis({ publicationTotal: 1500, statusCounts: { total: 1500, concept: 300, published: 1000, depublished: 200 } })).toEqual({
			catalogCount: 0,
			publicationCount: 1500,
			conceptPublicationCount: 300,
			publishedPublicationCount: 1000,
			depublishedPublicationCount: 200,
		})
	})

	it('keeps a zero aggregation count instead of the list count', () => {
		const result = kpis({ publicationTotal: 2, statusCounts: { total: 2, concept: 0, published: 2, depublished: 0 } })

		expect(result.conceptPublicationCount).toBe(0)
		expect(result.depublishedPublicationCount).toBe(0)
	})

	it('falls back to the fetched page without aggregation totals', () => {
		expect(kpis({})).toMatchObject({
			publicationCount: 4,
			conceptPublicationCount: 1,
			publishedPublicationCount: 2,
			depublishedPublicationCount: 1,
		})
	})

	it('stores the aggregation totals for the configured register and schema', async () => {
		const counts = { total: 9, concept: 1, published: 7, depublished: 1 }
		fetchPublicationStatusCounts.mockResolvedValue(counts)
		const state = { statusCounts: null, publicationTotal: 0 }

		await Dashboard.methods.fetchPublicationAggregations.call(state)

		expect(fetchPublicationStatusCounts).toHaveBeenCalledWith({
			register: 'reg-1',
			schema: 'sch-1',
			prefix: '',
			headers: { requesttoken: 'token-1' },
		})
		expect(state).toEqual({ statusCounts: counts, publicationTotal: 9 })
	})

	it('leaves the state alone when the aggregation is unavailable', async () => {
		fetchPublicationStatusCounts.mockResolvedValue(null)
		const state = { statusCounts: null, publicationTotal: 4 }

		await Dashboard.methods.fetchPublicationAggregations.call(state)

		expect(state).toEqual({ statusCounts: null, publicationTotal: 4 })
		expect(console.warn).toHaveBeenCalledWith('Failed to load publication count aggregations')
	})
})
