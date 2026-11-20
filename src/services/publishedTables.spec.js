/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { csvAttachments, dictionaryChanges, isCsv } from './publishedTables.js'

describe('publishedTables', () => {
	it('recognises a CSV by its name or media type', () => {
		expect(isCsv({ name: 'Speeltuinen.CSV' })).toBe(true)
		expect(isCsv({ name: 'data', type: 'text/csv' })).toBe(true)
		expect(isCsv({ name: 'besluit.pdf', type: 'application/pdf' })).toBe(false)
		expect(isCsv({})).toBe(false)
	})

	it('offers the CSV attachments that are not a table yet', () => {
		const files = {
			results: [
				{ id: 77, name: 'speeltuinen.csv' },
				{ id: 78, title: 'Parkeren', name: 'parkeren.csv' },
				{ id: 80, name: 'besluit.pdf' },
			],
		}
		expect(csvAttachments(files, [{ file: '77' }])).toEqual([{ id: '78', label: 'Parkeren' }])
		expect(csvAttachments([{ id: 5, name: 'a.csv' }], [])).toEqual([{ id: '5', label: 'a.csv' }])
		expect(csvAttachments(null, [])).toEqual([])
	})

	it('sends only the columns whose title or description changed', () => {
		const original = [
			{ name: 'naam', title: 'naam', description: '', type: 'string' },
			{ name: 'toestellen', title: 'toestellen', description: '', type: 'integer' },
		]
		const edited = [
			{ name: 'naam', title: 'naam', description: '', type: 'string' },
			{ name: 'toestellen', title: 'toestellen', description: 'Number of play devices on the site', type: 'integer' },
			{ name: 'onbekend', title: 'x', description: 'y' },
		]
		expect(dictionaryChanges(original, edited)).toEqual([
			{ name: 'toestellen', title: 'toestellen', description: 'Number of play devices on the site' },
		])
	})
})
