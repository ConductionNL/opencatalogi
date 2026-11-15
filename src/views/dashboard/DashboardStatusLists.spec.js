/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test for the status publication lists of `Dashboard.vue`: one widget per
 * publication status with its publications, empty text and status badge.
 */

import Dashboard from './Dashboard.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	registerIcons: () => {},
}))
jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
jest.mock('../../services/usageStats.js', () => ({ countingStartNote: () => null, fetchInstanceSeries: async () => [] }))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {} }))

// Nextcloud provides `t` globally; here it returns the source text.
global.t = (app, text) => text

describe('Dashboard statusPublicationLists', () => {
	it('lists the concept, published and depublished publications with their badges', () => {
		const ctx = { conceptPublications: ['c'], publishedPublications: ['p'], depublishedPublications: ['d'] }
		expect(Dashboard.computed.statusPublicationLists.call(ctx)).toEqual([
			{ id: 'concept-publications', publications: ['c'], emptyText: 'No concept publications', statusClass: 'concept-item-status', statusLabel: 'Concept' },
			{ id: 'published-publications', publications: ['p'], emptyText: 'No published publications', statusClass: 'published-item-status', statusLabel: 'Published' },
			{ id: 'depublished-publications', publications: ['d'], emptyText: 'No depublished publications', statusClass: 'depublished-item-status', statusLabel: 'Depublished' },
		])
	})
})
