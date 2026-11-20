/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the options the compact list widgets share: the
 * required title prop, the initial data and the fetch on mount.
 */

import CatalogiWidget from './CatalogiWidget.vue'
import UnpublishedAttachmentsWidget from './UnpublishedAttachmentsWidget.vue'
import UnpublishedPublicationsWidget from './UnpublishedPublicationsWidget.vue'
import { LIST_COLUMNS } from './widgetTable.js'

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	CnDataTable: { name: 'CnDataTable', render: () => null },
	registerIcons: () => {},
}))

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))

jest.mock('../../store/store.js', () => ({
	objectStore: { getCollection: () => ({ results: [] }), fetchCollection: jest.fn() },
}))

// The unpublished widgets also hold the overview they read (publications-unpublished-overview).
describe.each([
	['CatalogiWidget', CatalogiWidget, {}],
	['UnpublishedAttachmentsWidget', UnpublishedAttachmentsWidget, { overview: [] }],
	['UnpublishedPublicationsWidget', UnpublishedPublicationsWidget, { overview: [] }],
])('%s', (name, widget, extra) => {
	it('keeps its name and requires a string title', () => {
		expect(widget.name).toBe(name)
		expect(widget.props).toEqual({ title: { type: String, required: true } })
	})

	it('starts not loading, with the list columns', () => {
		const first = widget.data.call({})
		expect(first).toEqual({ loading: false, columns: LIST_COLUMNS, ...extra })
		expect(first.columns).toBe(LIST_COLUMNS)
		expect(widget.data.call({})).not.toBe(first)
	})

	it('fetches its data once on mount', () => {
		const ctx = { fetchData: jest.fn() }
		widget.mounted.call(ctx)
		expect(ctx.fetchData).toHaveBeenCalledTimes(1)
		expect(ctx.fetchData).toHaveBeenCalledWith()
	})
})
