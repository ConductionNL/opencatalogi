/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the widget definitions of `Dashboard.vue`:
 * every widget, in order, with its title and icon settings.
 */

import Dashboard, { attachmentCount } from './Dashboard.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	registerIcons: () => {},
}))

jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))

jest.mock('../../services/usageStats.js', () => ({
	countingStartNote: () => null,
	fetchInstanceSeries: async () => [],
}))

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { getCollection: () => ({ results: [] }) },
}))

// Nextcloud provides `t` globally; here it returns the source text.
global.t = (app, text) => text

describe('Dashboard widgetDefs', () => {
	it('lists the count, chart and status widgets in order', () => {
		expect(Dashboard.computed.widgetDefs.call({})).toEqual([
			{ id: 'count-publications', title: 'Publications', type: 'custom' },
			{ id: 'count-concept-publications', title: 'Concept Publications', type: 'custom' },
			{ id: 'count-published-publications', title: 'Published Publications', type: 'custom' },
			{ id: 'count-depublished-publications', title: 'Depublished Publications', type: 'custom' },
			{ id: 'count-catalogs', title: 'Catalogs', type: 'custom' },
			{ id: 'count-attachments', title: 'Attachments', type: 'custom' },
			{ id: 'publications-by-category', title: 'Publications by Category', type: 'custom' },
			{ id: 'activity', title: 'Activity', type: 'custom' },
			{ id: 'usage', title: 'Publication views', type: 'custom' },
			{ id: 'popular-searches', title: 'Popular Search Terms', type: 'custom' },
			{ id: 'concept-publications', title: 'Concept Publications', type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-warning)' },
			{ id: 'published-publications', title: 'Published Publications', type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-success)' },
			{ id: 'depublished-publications', title: 'Depublished Publications', type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-error)' },
		])
	})

	it('keeps the key order of each definition', () => {
		const defs = Dashboard.computed.widgetDefs.call({})
		expect(Object.keys(defs[0])).toEqual(['id', 'title', 'type'])
		expect(Object.keys(defs[10])).toEqual(['id', 'title', 'type', 'titleIconPosition', 'titleIconColor'])
	})
})

describe('Dashboard publicationsByCategoryData', () => {
	it('counts publications per schema name', () => {
		const { objectStore } = jest.requireMock('../../store/store.js')
		objectStore.availableSchemas = [{ id: 1, title: 'Doc' }, { id: 2, name: 'Note' }, { id: 3 }]
		const allPublications = [
			{ '@self': { schema: { title: 'Embedded' } } },
			{ '@self': { schema: { name: 'Named' } } },
			{ '@self': { schema: {} } },
			{ '@self': { schema: '1' } },
			{ '@self': { schema: 1 } },
			{ '@self': { schema: 2 } },
			{ '@self': { schema: 3 } },
			{ '@self': { schema: 99 } },
			{ '@self': {} },
			{},
		]
		expect(Dashboard.computed.publicationsByCategoryData.call({ allPublications })).toEqual({
			// Integer-like names come first, as object keys do.
			labels: ['3', '99', 'Embedded', 'Named', 'Unknown', 'Doc', 'Note'],
			series: [1, 1, 1, 1, 3, 2, 1],
		})
	})
})

describe('Dashboard counts', () => {
	it('counts the attachments each publication carries, as a number or a list', () => {
		expect(attachmentCount([])).toBe(0)
		expect(attachmentCount([
			{ '@self': { files: 2 } },
			{ '@self': { files: ['a', 'b', 'c'] } },
			{ '@self': { files: '4' } },
			{ '@self': { files: null } },
			{ '@self': { files: -1 } },
			{ '@self': { files: 'x' } },
			{ '@self': {} },
			{},
			null,
		])).toBe(9)
	})

	it('reports catalogues, publications and attachments in the KPIs', () => {
		const kpis = Dashboard.computed.kpis.call({
			catalogs: [{ id: 1 }, { id: 2 }, { id: 3 }],
			publicationTotal: 40,
			allPublications: [{ '@self': { files: 90 } }, { '@self': { files: 5 } }],
			conceptPublications: [],
			publishedPublications: [],
			depublishedPublications: [],
		})
		expect(kpis.catalogCount).toBe(3)
		expect(kpis.publicationCount).toBe(40)
		expect(kpis.attachmentCount).toBe(95)
	})

	it('shows zero for every count on a new installation', () => {
		const kpis = Dashboard.computed.kpis.call({
			catalogs: [],
			publicationTotal: 0,
			allPublications: [],
			conceptPublications: [],
			publishedPublications: [],
			depublishedPublications: [],
		})
		expect(kpis).toEqual(expect.objectContaining({ catalogCount: 0, publicationCount: 0, attachmentCount: 0 }))
	})
})
