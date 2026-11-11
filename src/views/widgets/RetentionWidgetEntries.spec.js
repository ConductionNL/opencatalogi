/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the rows of `RetentionWidget.vue`: one row per retention
 * count above zero, in a fixed order.
 */

import RetentionWidget from './RetentionWidget.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))

const entries = (summary) => RetentionWidget.computed.entries.call({ summary })

describe('RetentionWidget entries', () => {
	it('has no rows when nothing needs attention', () => {
		expect(entries({ expiringSoon: 0, reviewRequired: 0, archived: 0 })).toEqual([])
	})

	it('lists every count above zero in order', () => {
		const rows = entries({ expiringSoon: 2, reviewRequired: 3, archived: 4 })

		expect(rows).toEqual([
			{ id: 'expiring', label: 'Expiring soon', count: 2 },
			{ id: 'review', label: 'Retention review required', count: 3 },
			{ id: 'archived', label: 'Archived', count: 4 },
		])
		expect(rows.map((row) => Object.keys(row))).toEqual([
			['id', 'label', 'count'],
			['id', 'label', 'count'],
			['id', 'label', 'count'],
		])
	})

	it.each([
		[{ expiringSoon: 1, reviewRequired: 0, archived: 0 }, ['expiring']],
		[{ expiringSoon: 0, reviewRequired: 5, archived: 0 }, ['review']],
		[{ expiringSoon: 0, reviewRequired: 0, archived: 7 }, ['archived']],
		[{ expiringSoon: -1, reviewRequired: '2', archived: undefined }, ['review']],
	])('keeps only the counts above zero of %j', (summary, ids) => {
		expect(entries(summary).map((row) => row.id)).toEqual(ids)
	})
})
