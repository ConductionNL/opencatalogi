/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/publicationStatusCounts.js: the ad-hoc count
 * queries, the derivation of the concept / published / depublished totals
 * from three counts, and the requests made to OpenRegister.
 */
import { describe, expect, it, vi } from 'vitest'
import {
	countQuery,
	deriveStatusCounts,
	fetchPublicationStatusCounts,
	statusCountFilters,
} from '../../src/services/publicationStatusCounts.js'

const NOW = new Date('2026-11-13T12:00:00.000Z')
const NOW_ISO = '2026-11-13T12:00:00.000Z'

/**
 * A fetch that answers each query with the count mapped to it.
 *
 * @param {object<string, *>} counts Query string → answer: a count, or `{ status }` for a failure.
 * @return {Function} The fetch mock.
 */
function fetchAnswering(counts) {
	return vi.fn(async (url) => {
		const answer = counts[url.split('?')[1]]
		if (answer && typeof answer === 'object') {
			return { ok: false, status: answer.status, json: async () => ({}) }
		}
		return { ok: true, json: async () => ({ value: answer }) }
	})
}

const TOTAL = 'metric=count'
const LIVE = `metric=count&${encodeURIComponent('filter[publicationDate][lte]')}=${encodeURIComponent(NOW_ISO)}`
const ENDED = `${LIVE}&${encodeURIComponent('filter[depublicationDate][lte]')}=${encodeURIComponent(NOW_ISO)}`

describe('countQuery', () => {
	it('asks for a plain count without filters', () => {
		expect(countQuery()).toBe('metric=count')
	})

	it('adds one lte filter per field', () => {
		const params = new URLSearchParams(countQuery({ publicationDate: NOW_ISO, depublicationDate: NOW_ISO }))

		expect([...params.entries()]).toEqual([
			['metric', 'count'],
			['filter[publicationDate][lte]', NOW_ISO],
			['filter[depublicationDate][lte]', NOW_ISO],
		])
	})
})

describe('statusCountFilters', () => {
	it('bounds live by the publication date and ended by both dates', () => {
		expect(statusCountFilters(NOW_ISO)).toEqual({
			total: {},
			live: { publicationDate: NOW_ISO },
			ended: { publicationDate: NOW_ISO, depublicationDate: NOW_ISO },
		})
	})
})

describe('deriveStatusCounts', () => {
	it('splits the total into concept, published and depublished', () => {
		expect(deriveStatusCounts({ total: 1500, live: 1200, ended: 200 })).toEqual({
			total: 1500,
			concept: 300,
			published: 1000,
			depublished: 200,
		})
	})

	it('allows every count to be zero', () => {
		expect(deriveStatusCounts({ total: 0, live: 0, ended: 0 })).toEqual({
			total: 0,
			concept: 0,
			published: 0,
			depublished: 0,
		})
	})

	it.each([
		[{ total: null, live: 1, ended: 0 }],
		[{ total: 3, live: undefined, ended: 0 }],
		[{ total: 3, live: 1, ended: 1.5 }],
		[{ total: 3, live: -1, ended: 0 }],
	])('refuses a missing or invalid count %o', (counts) => {
		expect(deriveStatusCounts(counts)).toBeNull()
	})

	it.each([
		[{ total: 3, live: 4, ended: 0 }],
		[{ total: 5, live: 2, ended: 3 }],
	])('refuses counts that contradict each other %o', (counts) => {
		expect(deriveStatusCounts(counts)).toBeNull()
	})
})

describe('fetchPublicationStatusCounts', () => {
	it('counts total, live and ended at the same moment and derives the statuses', async () => {
		const fetchFn = fetchAnswering({ [TOTAL]: 10, [LIVE]: 7, [ENDED]: 2 })

		const counts = await fetchPublicationStatusCounts({
			register: 'r 1',
			schema: 's1',
			prefix: '/index.php',
			headers: { requesttoken: 'x' },
			now: NOW,
			fetchFn,
		})

		expect(counts).toEqual({ total: 10, concept: 3, published: 5, depublished: 2 })
		expect(fetchFn).toHaveBeenCalledTimes(3)
		const base = '/index.php/apps/openregister/api/objects/aggregations/r%201/s1/value'
		expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([
			`${base}?${TOTAL}`,
			`${base}?${LIVE}`,
			`${base}?${ENDED}`,
		])
		for (const [, init] of fetchFn.mock.calls) {
			expect(init).toEqual({ method: 'GET', headers: { requesttoken: 'x' } })
		}
	})

	it.each([
		[{ register: '', schema: 's1' }],
		[{ register: 'r1', schema: '' }],
	])('makes no request without a register and schema %o', async (ids) => {
		const fetchFn = vi.fn()

		expect(await fetchPublicationStatusCounts({ ...ids, now: NOW, fetchFn })).toBeNull()
		expect(fetchFn).not.toHaveBeenCalled()
	})

	it('gives up when one count is refused', async () => {
		const fetchFn = fetchAnswering({ [TOTAL]: 10, [LIVE]: { status: 500 }, [ENDED]: 2 })

		expect(await fetchPublicationStatusCounts({ register: 'r1', schema: 's1', now: NOW, fetchFn })).toBeNull()
	})

	it('gives up when a count is not a number', async () => {
		const fetchFn = fetchAnswering({ [TOTAL]: '10', [LIVE]: 7, [ENDED]: 2 })

		expect(await fetchPublicationStatusCounts({ register: 'r1', schema: 's1', now: NOW, fetchFn })).toBeNull()
	})

	it('gives up when the network fails', async () => {
		const fetchFn = vi.fn(async () => {
			throw new Error('offline')
		})

		expect(await fetchPublicationStatusCounts({ register: 'r1', schema: 's1', now: NOW, fetchFn })).toBeNull()
	})
})
