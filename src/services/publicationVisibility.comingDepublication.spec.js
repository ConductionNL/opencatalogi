/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Only a depublication date that lies ahead is an automatic depublication
 * (REQ-PSG-003).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */

import { comingDepublication } from './publicationVisibility.js'

const NOW = new Date('2026-11-24T12:00:00Z')

describe('comingDepublication', () => {
	it('answers a future depublication date', () => {
		expect(comingDepublication({ depublicationDate: '2026-12-01T00:00:00Z' }, NOW)).toEqual(new Date('2026-12-01T00:00:00Z'))
	})

	it('answers null for a past, absent, unreadable date or no publication', () => {
		expect(comingDepublication({ depublicationDate: '2026-11-01T00:00:00Z' }, NOW)).toBeNull()
		expect(comingDepublication({ depublicationDate: '2026-11-24T12:00:00Z' }, NOW)).toBeNull()
		expect(comingDepublication({ depublicationDate: '' }, NOW)).toBeNull()
		expect(comingDepublication({ depublicationDate: 'soon' }, NOW)).toBeNull()
		expect(comingDepublication({}, NOW)).toBeNull()
		expect(comingDepublication(null, NOW)).toBeNull()
	})

	it('compares with the current moment by default', () => {
		expect(comingDepublication({ depublicationDate: '2999-01-01T00:00:00Z' })).not.toBeNull()
		expect(comingDepublication({ depublicationDate: '2000-01-01T00:00:00Z' })).toBeNull()
	})
})
