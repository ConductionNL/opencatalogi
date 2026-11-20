/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which files of a files response are withdrawn: those whose own window has ended.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
 */

import { isWithdrawnFile, withdrawnDocuments } from './publicationVisibility.js'

const NOW = new Date('2026-10-02T12:00:00Z')

describe('isWithdrawnFile', () => {
	it('is withdrawn when the end is now or past', () => {
		expect(isWithdrawnFile({ depublished: '2026-10-02T12:00:00Z' }, NOW)).toBe(true)
		expect(isWithdrawnFile({ depublished: '2026-10-01T00:00:00Z' }, NOW)).toBe(true)
	})

	it('is not withdrawn with a future end, no end, an unreadable end or no file', () => {
		expect(isWithdrawnFile({ depublished: '2026-10-02T12:00:01Z' }, NOW)).toBe(false)
		expect(isWithdrawnFile({ depublished: null }, NOW)).toBe(false)
		expect(isWithdrawnFile({}, NOW)).toBe(false)
		expect(isWithdrawnFile({ depublished: 'soon' }, NOW)).toBe(false)
		expect(isWithdrawnFile(null, NOW)).toBe(false)
	})
})

describe('withdrawnDocuments', () => {
	it('names each withdrawn file by title, name or id, with its moment and reason', () => {
		const data = {
			results: [
				{ id: 1, title: 'A.pdf', depublished: '2026-10-01T00:00:00Z', 'withdrawn-reason': 'BSN' },
				{ id: 2, name: 'b.pdf', depublished: '2026-10-01T00:00:00Z' },
				{ id: 3, depublished: '2026-10-01T00:00:00Z' },
				{ id: 4, title: 'open.pdf' },
			],
		}

		expect(withdrawnDocuments(data, NOW)).toEqual([
			{ id: 1, label: 'A.pdf', withdrawnAt: '2026-10-01T00:00:00Z', reason: 'BSN' },
			{ id: 2, label: 'b.pdf', withdrawnAt: '2026-10-01T00:00:00Z', reason: '' },
			{ id: 3, label: '3', withdrawnAt: '2026-10-01T00:00:00Z', reason: '' },
		])
	})

	it('reads a bare array as well as {results}, and nothing from an empty answer', () => {
		expect(withdrawnDocuments([{ id: 1, depublished: '2026-10-01T00:00:00Z' }], NOW)).toHaveLength(1)
		expect(withdrawnDocuments({}, NOW)).toEqual([])
	})
})
