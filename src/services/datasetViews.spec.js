/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `datasetViews.js`: the columns a view may use, the record a
 * form saves, what a form still lacks, the positions a move writes, and the
 * figures of a preview.
 */

import { columnNames, missingField, movedViews, numericColumns, previewFigures, viewForm, viewPayload } from './datasetViews.js'

const DICTIONARY = [
	{ name: 'wijk', type: 'string' },
	{ name: 'toestellen', type: 'integer' },
	{ name: 'lat', type: 'number' },
	{ name: 'lon', type: 'number' },
]

describe('datasetViews', () => {
	it('lists every column, and only the integer and number columns as numeric', () => {
		expect(columnNames(DICTIONARY)).toEqual(['wijk', 'toestellen', 'lat', 'lon'])
		expect(numericColumns(DICTIONARY)).toEqual(['toestellen', 'lat', 'lon'])
		expect(columnNames(undefined)).toEqual([])
		expect(numericColumns(null)).toEqual([])
	})

	it('starts a new view as a table counted per group, and keeps a saved view\'s values', () => {
		expect(viewForm(null)).toEqual({
			kind: 'table', title: '', columns: [], x: '', y: '', aggregate: 'count', latitude: '', longitude: '', label: '',
		})

		const form = viewForm({ id: 'v1', kind: 'bar', title: 'Per wijk', x: 'wijk', y: 'toestellen', aggregate: 'sum', position: 2 })
		expect(form).toMatchObject({ kind: 'bar', title: 'Per wijk', x: 'wijk', y: 'toestellen', aggregate: 'sum' })
		expect(viewForm({ kind: 'pie', aggregate: 'median' })).toMatchObject({ kind: 'table', aggregate: 'count' })
	})

	it('saves only the fields of the chosen kind, without an empty optional field', () => {
		const form = { ...viewForm(null), kind: 'map', title: ' Speeltuinen ', columns: ['wijk'], x: 'wijk', latitude: 'lat', longitude: 'lon' }

		expect(viewPayload(form)).toEqual({ kind: 'map', title: 'Speeltuinen', latitude: 'lat', longitude: 'lon' })
	})

	it('leaves y out of a count, and carries the view id and position when given', () => {
		const counted = { ...viewForm(null), kind: 'line', title: 'Trend', x: 'wijk', y: 'toestellen', aggregate: 'count' }
		const summed = { ...counted, aggregate: 'sum' }

		expect(viewPayload(counted)).toEqual({ kind: 'line', title: 'Trend', x: 'wijk', aggregate: 'count' })
		expect(viewPayload(summed, 'v7', 0)).toEqual({ kind: 'line', title: 'Trend', x: 'wijk', y: 'toestellen', aggregate: 'sum', position: 0, viewId: 'v7' })
	})

	it('names what a form still lacks, in the order the dialog asks for it', () => {
		const base = viewForm(null)

		expect(missingField({ ...base, title: '  ' })).toBe('title')
		expect(missingField({ ...base, title: 'Rijen' })).toBe('columns')
		expect(missingField({ ...base, title: 'Rijen', columns: ['wijk'] })).toBeNull()
		expect(missingField({ ...base, kind: 'bar', title: 'Per wijk' })).toBe('x')
		expect(missingField({ ...base, kind: 'bar', title: 'Per wijk', x: 'wijk', aggregate: 'avg' })).toBe('y')
		expect(missingField({ ...base, kind: 'bar', title: 'Per wijk', x: 'wijk' })).toBeNull()
		expect(missingField({ ...base, kind: 'map', title: 'Kaart', latitude: 'lat' })).toBe('coordinates')
		expect(missingField({ ...base, kind: 'map', title: 'Kaart', latitude: 'lat', longitude: 'lon' })).toBeNull()
	})

	it('swaps a view with its neighbour, and refuses a move off the list', () => {
		const views = [{ id: 'a' }, { id: 'b' }, { id: 'c' }]

		expect(movedViews(views, 1, -1)).toEqual([{ view: { id: 'b' }, position: 0 }, { view: { id: 'a' }, position: 1 }])
		expect(movedViews(views, 1, 1)).toEqual([{ view: { id: 'b' }, position: 2 }, { view: { id: 'c' }, position: 1 }])
		expect(movedViews(views, 0, -1)).toBeNull()
		expect(movedViews(views, 2, 1)).toBeNull()
	})

	it('reads the figures of each kind of data answer', () => {
		expect(previewFigures('table', { results: [{}, {}], total: 40 })).toEqual({ count: 2, total: 40, truncated: false, skipped: 0 })
		expect(previewFigures('bar', { points: [{}, {}, {}], truncated: true })).toEqual({ count: 3, total: 3, truncated: true, skipped: 0 })
		expect(previewFigures('map', { features: [{}], skipped: 4, truncated: false })).toEqual({ count: 1, total: 1, truncated: false, skipped: 4 })
		expect(previewFigures('table', null)).toEqual({ count: 0, total: 0, truncated: false, skipped: 0 })
	})
})
