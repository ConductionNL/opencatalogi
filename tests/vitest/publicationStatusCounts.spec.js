/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/publicationStatusCounts.js: the ad-hoc count
 * queries, the derivation of the per-status totals from those counts, and the
 * requests made to OpenRegister.
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

const param = (field, op, value) => `${encodeURIComponent(`filter[${field}][${op}]`)}=${encodeURIComponent(value)}`
const PUB_LTE = param('publicationDate', 'lte', NOW_ISO)
const DEPUB_LTE = param('depublicationDate', 'lte', NOW_ISO)
const PUB_NULL = param('publicationDate', 'isnull', 'true')
const PUB_GT = param('publicationDate', 'gt', NOW_ISO)

const TOTAL = 'metric=count'
const LIVE = `metric=count&${PUB_LTE}`
const ENDED = `${LIVE}&${DEPUB_LTE}`
const WITHDRAWN = `metric=count&${DEPUB_LTE}`
const UNDATED = `metric=count&${PUB_NULL}`
const UNDATED_WITHDRAWN = `${UNDATED}&${DEPUB_LTE}`
const UPCOMING = `metric=count&${PUB_GT}`
const UPCOMING_WITHDRAWN = `${UPCOMING}&${DEPUB_LTE}`
const WINDOWS = `${PUB_GT}&${param('depublicationDate', 'gt', NOW_ISO)}`

/**
 * One page of the upcoming-window list.
 *
 * @param {Array<Array<string>>} windows [publicationDate, depublicationDate] per publication.
 * @param {number} pages The number of pages.
 * @return {object} The response body.
 */
function listPage(windows, pages = 1) {
	return {
		results: windows.map(([publicationDate, depublicationDate]) => ({ publicationDate, depublicationDate })),
		total: windows.length * pages,
		pages,
	}
}

/**
 * A fetch that answers each count query with the count mapped to it, and each
 * list page with the body mapped to its page number.
 *
 * @param {object<string, *>} counts Query string → a count, or `{ status }` for a failure.
 * @param {object<number, object>} pages Page number → list body.
 * @return {Function} The fetch mock.
 */
function fetchAnswering(counts, pages = { 1: listPage([]) }) {
	return vi.fn(async (url) => {
		const query = url.split('?')[1]
		if (url.includes('/aggregations/')) {
			const answer = counts[query]
			if (answer && typeof answer === 'object') {
				return { ok: false, status: answer.status, json: async () => ({}) }
			}
			return { ok: true, json: async () => ({ value: answer }) }
		}
		const page = Number(new URLSearchParams(query).get('_page'))
		return { ok: true, json: async () => pages[page] }
	})
}

const COUNTS = {
	[TOTAL]: 20,
	[LIVE]: 9,
	[ENDED]: 2,
	[WITHDRAWN]: 4,
	[UNDATED]: 6,
	[UNDATED_WITHDRAWN]: 1,
	[UPCOMING]: 5,
	[UPCOMING_WITHDRAWN]: 1,
}

describe('countQuery', () => {
	it('asks for a plain count without filters', () => {
		expect(countQuery()).toBe('metric=count')
	})

	it('adds one filter per field and operator', () => {
		const params = new URLSearchParams(countQuery({
			publicationDate: { isnull: 'true' },
			depublicationDate: { lte: NOW_ISO },
		}))

		expect([...params.entries()]).toEqual([
			['metric', 'count'],
			['filter[publicationDate][isnull]', 'true'],
			['filter[depublicationDate][lte]', NOW_ISO],
		])
	})
})

describe('statusCountFilters', () => {
	it('bounds every count at the same moment', () => {
		const lte = { lte: NOW_ISO }
		const isnull = { isnull: 'true' }
		const gt = { gt: NOW_ISO }

		expect(statusCountFilters(NOW_ISO)).toEqual({
			total: {},
			live: { publicationDate: lte },
			ended: { publicationDate: lte, depublicationDate: lte },
			withdrawn: { depublicationDate: lte },
			undated: { publicationDate: isnull },
			undatedWithdrawn: { publicationDate: isnull, depublicationDate: lte },
			upcoming: { publicationDate: gt },
			upcomingWithdrawn: { publicationDate: gt, depublicationDate: lte },
		})
	})
})

describe('deriveStatusCounts', () => {
	const valid = { total: 20, live: 9, ended: 2, withdrawn: 4, undated: 6, undatedWithdrawn: 1, upcoming: 5, upcomingWithdrawn: 1, invalid: 1 }

	it('splits the total over concept, scheduled, published, depublished and invalid', () => {
		expect(deriveStatusCounts(valid)).toEqual({
			total: 20,
			concept: 5,
			scheduled: 3,
			published: 7,
			depublished: 4,
			invalid: 1,
		})
	})

	it('allows every count to be zero', () => {
		const zero = Object.fromEntries(Object.keys(valid).map((name) => [name, 0]))

		expect(deriveStatusCounts(zero)).toEqual({
			total: 0,
			concept: 0,
			scheduled: 0,
			published: 0,
			depublished: 0,
			invalid: 0,
		})
	})

	it('keeps the other statuses without the invalid-window count', () => {
		expect(deriveStatusCounts({ ...valid, invalid: null })).toEqual({
			total: 20,
			concept: 5,
			scheduled: null,
			published: 7,
			depublished: 4,
			invalid: null,
		})
	})

	it.each([
		[{ ...valid, total: null }],
		[{ ...valid, live: undefined }],
		[{ ...valid, upcoming: undefined }],
		[{ ...valid, ended: 1.5 }],
		[{ ...valid, invalid: -1 }],
	])('refuses a missing or invalid count %o', (counts) => {
		expect(deriveStatusCounts(counts)).toBeNull()
	})

	it.each([
		[{ ...valid, ended: 10 }],
		[{ ...valid, ended: 5 }],
		[{ ...valid, undatedWithdrawn: 7 }],
		[{ ...valid, upcomingWithdrawn: 6 }],
		[{ ...valid, invalid: 5 }],
	])('refuses counts that contradict each other %o', (counts) => {
		expect(deriveStatusCounts(counts)).toBeNull()
	})

	it('refuses statuses that do not add up to the total, as with a publication date the filters cannot place', () => {
		expect(deriveStatusCounts({ ...valid, total: 21 })).toBeNull()
	})
})

describe('fetchPublicationStatusCounts', () => {
	const options = { register: 'r 1', schema: 's1', prefix: '/index.php', headers: { requesttoken: 'x' }, now: NOW }

	it('counts at one moment, reads the upcoming windows and derives the statuses', async () => {
		const fetchFn = fetchAnswering(COUNTS, {
			1: listPage([['2026-12-01', '2026-12-10'], ['2026-12-01', '2026-11-30']], 2),
			2: listPage([['2026-12-05', '2026-12-05'], ['2026-12-05', '2026-12-06']], 2),
		})

		const counts = await fetchPublicationStatusCounts({ ...options, fetchFn })

		expect(counts).toEqual({ total: 20, concept: 5, scheduled: 2, published: 7, depublished: 4, invalid: 2 })
		const base = '/index.php/apps/openregister/api/objects'
		const aggregation = `${base}/aggregations/r%201/s1/value`
		const list = `${base}/r%201/s1?${WINDOWS}&_fields=publicationDate%2CdepublicationDate&_limit=500`
		expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([
			`${aggregation}?${TOTAL}`,
			`${aggregation}?${LIVE}`,
			`${aggregation}?${ENDED}`,
			`${aggregation}?${WITHDRAWN}`,
			`${aggregation}?${UNDATED}`,
			`${aggregation}?${UNDATED_WITHDRAWN}`,
			`${aggregation}?${UPCOMING}`,
			`${aggregation}?${UPCOMING_WITHDRAWN}`,
			`${list}&_page=1`,
			`${list}&_page=2`,
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
		const fetchFn = fetchAnswering({ ...COUNTS, [LIVE]: { status: 500 } })

		expect(await fetchPublicationStatusCounts({ ...options, fetchFn })).toBeNull()
	})

	it('gives up when a count is not a number', async () => {
		const fetchFn = fetchAnswering({ ...COUNTS, [TOTAL]: '20' })

		expect(await fetchPublicationStatusCounts({ ...options, fetchFn })).toBeNull()
	})

	it('keeps the other statuses when the upcoming windows cannot be read', async () => {
		const fetchFn = fetchAnswering(COUNTS, { 1: { total: 3 } })

		expect(await fetchPublicationStatusCounts({ ...options, fetchFn })).toEqual({
			total: 20,
			concept: 5,
			scheduled: null,
			published: 7,
			depublished: 4,
			invalid: null,
		})
	})

	it('gives up when the network fails', async () => {
		const fetchFn = vi.fn(async () => {
			throw new Error('offline')
		})

		expect(await fetchPublicationStatusCounts({ ...options, fetchFn })).toBeNull()
	})
})
