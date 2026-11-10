/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the widget definitions of `Dashboard.vue`:
 * every widget, in order, with its title and icon settings.
 */

import Dashboard from './Dashboard.vue'

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
		expect(Object.keys(defs[8])).toEqual(['id', 'title', 'type', 'titleIconPosition', 'titleIconColor'])
	})
})
