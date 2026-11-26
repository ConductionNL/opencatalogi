/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/publicationStatus.js: the status of a
 * publication for every combination of its publication and depublication
 * date: not set, on or before now, or after now. The numbers name the cases.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	getPublicationStatus,
	hasInvalidWindow,
	isConcept,
	isDepublished,
	isPublished,
	isScheduled,
} from '../../src/services/publicationStatus.js'

const NOW = new Date('2026-11-26T12:00:00.000Z')
const PAST = '2026-11-16T12:00:00+00:00'
const EARLIER = '2026-11-06T12:00:00+00:00'
const FUTURE = '2026-12-06T12:00:00+00:00'
const LATER = '2026-12-16T12:00:00+00:00'
const SOONER = '2026-12-01T12:00:00+00:00'

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(NOW)
})

afterEach(() => vi.useRealTimers())

describe('getPublicationStatus', () => {
	it.each([
		['1: no dates', {}, 'concept'],
		['2: only a coming depublication', { depublicationDate: FUTURE }, 'concept'],
		['3: only a past depublication', { depublicationDate: PAST }, 'depublished'],
		['4: a coming publication', { publicationDate: FUTURE }, 'scheduled'],
		['5: a coming publication with a later end', { publicationDate: FUTURE, depublicationDate: LATER }, 'scheduled'],
		['6: a coming publication that ends before it starts', { publicationDate: FUTURE, depublicationDate: SOONER }, 'invalid'],
		['6: a coming publication that ends when it starts', { publicationDate: FUTURE, depublicationDate: FUTURE }, 'invalid'],
		['6: a coming publication whose end has passed', { publicationDate: FUTURE, depublicationDate: PAST }, 'depublished'],
		['7: a past publication', { publicationDate: PAST }, 'published'],
		['8: a past publication with a coming end', { publicationDate: PAST, depublicationDate: FUTURE }, 'published'],
		['9: a past publication whose end has passed', { publicationDate: EARLIER, depublicationDate: PAST }, 'depublished'],
	])('row %s is %s', (row, dates, status) => {
		expect(getPublicationStatus(dates)).toBe(status)
	})

	it('reads a publication date of now as published and a depublication date of now as depublished', () => {
		const now = NOW.toISOString()

		expect(getPublicationStatus({ publicationDate: now })).toBe('published')
		expect(getPublicationStatus({ publicationDate: PAST, depublicationDate: now })).toBe('depublished')
	})

	it('treats an unreadable date as not set', () => {
		expect(getPublicationStatus({ publicationDate: 'not a date', depublicationDate: '' })).toBe('concept')
		expect(getPublicationStatus(null)).toBe('concept')
	})
})

describe('the status predicates', () => {
	it('agree with getPublicationStatus', () => {
		const scheduled = { publicationDate: FUTURE }
		const published = { publicationDate: PAST }
		const depublished = { depublicationDate: PAST }

		expect([isConcept({}), isScheduled(scheduled), isPublished(published), isDepublished(depublished)]).toEqual([true, true, true, true])
		expect([isConcept(scheduled), isScheduled(published), isPublished(depublished), isDepublished({})]).toEqual([false, false, false, false])
	})
})

describe('hasInvalidWindow', () => {
	it('flags a coming publication that ends on or before it starts', () => {
		expect(hasInvalidWindow({ publicationDate: FUTURE, depublicationDate: SOONER })).toBe(true)
		expect(hasInvalidWindow({ publicationDate: FUTURE, depublicationDate: FUTURE })).toBe(true)
	})

	it('leaves a valid, finished or taken-down window alone', () => {
		expect(hasInvalidWindow({ publicationDate: FUTURE, depublicationDate: PAST })).toBe(false)
		expect(hasInvalidWindow({ publicationDate: FUTURE, depublicationDate: LATER })).toBe(false)
		expect(hasInvalidWindow({ publicationDate: FUTURE })).toBe(false)
		expect(hasInvalidWindow({ publicationDate: PAST, depublicationDate: EARLIER })).toBe(false)
		expect(hasInvalidWindow({ depublicationDate: PAST })).toBe(false)
	})
})
