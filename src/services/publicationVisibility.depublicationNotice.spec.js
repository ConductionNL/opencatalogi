/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page says at save that a publication comes down by itself: the first
 * depublication date of a record is its baseline, a later change to a coming
 * date is announced, and a cleared or past date clears the notice
 * (REQ-PSG-003).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */

import { depublicationKeyOf, depublicationNoticeStep } from './publicationVisibility.js'

const NOW = new Date('2026-11-24T12:00:00Z')

describe('depublicationKeyOf', () => {
	it('joins the id and the depublication date', () => {
		expect(depublicationKeyOf({ id: 'p1', depublicationDate: '2026-12-01' })).toBe('p1|2026-12-01')
		expect(depublicationKeyOf({ '@self': { id: 'p2' } })).toBe('p2|')
		expect(depublicationKeyOf({ uuid: 'p3', depublicationDate: null })).toBe('p3|')
	})

	it('answers null without a loaded record', () => {
		expect(depublicationKeyOf(null)).toBeNull()
		expect(depublicationKeyOf({})).toBeNull()
		expect(depublicationKeyOf('p1')).toBeNull()
	})
})

describe('depublicationNoticeStep', () => {
	it('takes the first key of a record as its baseline and says nothing', () => {
		expect(depublicationNoticeStep(null, 'p1|2026-12-01', NOW)).toEqual({ baseline: { id: 'p1', date: '2026-12-01' }, date: null })
		expect(depublicationNoticeStep({ id: 'p0', date: '' }, 'p1|', NOW)).toEqual({ baseline: { id: 'p1', date: '' }, date: null })
	})

	it('announces a date set or changed to one ahead', () => {
		const step = depublicationNoticeStep({ id: 'p1', date: '' }, 'p1|2026-12-01T00:00:00Z', NOW)
		expect(step.baseline).toEqual({ id: 'p1', date: '2026-12-01T00:00:00Z' })
		expect(step.date).toEqual(new Date('2026-12-01T00:00:00Z'))
	})

	it('clears the notice for a cleared or past date', () => {
		expect(depublicationNoticeStep({ id: 'p1', date: '2026-12-01' }, 'p1|', NOW).date).toBeNull()
		expect(depublicationNoticeStep({ id: 'p1', date: '' }, 'p1|2026-01-01T00:00:00Z', NOW).date).toBeNull()
	})

	it('leaves the notice as it is for the same key or no record', () => {
		const baseline = { id: 'p1', date: '2026-12-01' }
		expect(depublicationNoticeStep(baseline, 'p1|2026-12-01', NOW)).toEqual({ baseline, date: undefined })
		expect(depublicationNoticeStep(baseline, null, NOW)).toEqual({ baseline, date: undefined })
	})
})
