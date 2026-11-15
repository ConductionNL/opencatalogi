/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the JS search store's searchPublications: the request it
 * sends, every store action it runs with its arguments, the state it leaves
 * and its console output, for a full answer, an answer without fields, page
 * and limit overrides (set, zero and absent), facets with and without
 * facetable, a failed response, a thrown error and a thrown non-error. Run
 * with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.js'

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/search-publications-js-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'

const full = {
	results: [{ id: 'p1' }, { id: 'p2' }],
	page: 2,
	pages: 4,
	total: 61,
	limit: 20,
	offset: 20,
	facets: { status: { buckets: [] } },
	facetable: { '@self': { register: {} } },
}

/**
 * A fetch that answers with the given body.
 *
 * @param {object} body The JSON body.
 * @return {Function} The fetch stand-in.
 */
function okWith(body) {
	return async () => ({ ok: true, json: async () => body })
}

const SCENARIOS = {
	full: { fetch: okWith(full) },
	empty: { fetch: okWith({}) },
	zeros: {
		fetch: okWith({
			results: [],
			page: 0,
			pages: 0,
			total: 0,
			limit: 0,
			offset: 0,
		}),
	},
	pageAndLimit: {
		params: { _page: 3, _limit: 50, theme: 'x' },
		fetch: okWith(full),
	},
	zeroPageAndLimit: { params: { _page: 0, _limit: 0 }, fetch: okWith(full) },
	pageOnly: { params: { _page: '2' }, fetch: okWith({ results: null, total: 5 }) },
	facetsOnly: { fetch: okWith({ results: [{ id: 'a' }], facets: { a: 1 } }) },
	facetableOnly: {
		fetch: okWith({ results: [{ id: 'a' }], facetable: { b: 2 } }),
	},
	termAndFilters: {
		setup: (store) => {
			store.searchTerm = 'park'
			store.filters = { status: 'published' }
			store.ordering = { title: 'asc' }
		},
		fetch: okWith(full),
	},
	pageAndLimitThenFailure: {
		params: { _page: 4, _limit: 30 },
		fetch: async () => ({ ok: false, status: 500, statusText: 'Error' }),
	},
	notOk: {
		fetch: async () => ({ ok: false, status: 503, statusText: 'Unavailable' }),
	},
	throws: {
		fetch: async () => {
			throw new Error('network down')
		},
	},
	throwsNonError: {
		fetch: async () => {
			throw 'plain string'
		},
	},
	badJson: {
		fetch: async () => ({
			ok: true,
			json: async () => {
				throw new SyntaxError('bad json')
			},
		}),
	},
}

/**
 * Run one scenario and record what the store did.
 *
 * @param {object} scenario The setup, params and fetch behaviour.
 * @return {Promise<object>} The trace.
 */
async function trace(scenario) {
	const store = useSearchStore()
	scenario.setup?.(store)
	const actions = []
	store.$onAction(({ name, args }) => {
		actions.push([name, JSON.parse(JSON.stringify(args ?? null))])
	})
	globalThis.fetch = vi.fn(scenario.fetch)
	const info = vi.spyOn(console, 'info').mockImplementation(() => {})
	const error = vi.spyOn(console, 'error').mockImplementation(() => {})
	const out = {}
	try {
		out.returned = (await store.searchPublications(scenario.params)) ?? null
	} catch (e) {
		out.thrown = String(e)
	}
	out.fetchCalls = globalThis.fetch.mock.calls
	out.actions = actions
	out.info = info.mock.calls
	out.error = error.mock.calls.map(([label, e]) => [label, String(e)])
	out.state = {
		searchResults: store.searchResults,
		isLoading: store.isLoading,
		error: store.error,
		pagination: store.pagination,
		facets: store.facets,
		facetable: store.facetable,
	}
	return out
}

describe('search store searchPublications, JS module (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it.each(Object.keys(SCENARIOS))(
		'matches the recorded trace for %s',
		async (name) => {
			const actual = JSON.parse(JSON.stringify(await trace(SCENARIOS[name])))
			const golden = fs.existsSync(FIXTURE)
				? JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
				: {}
			if (RECORD) {
				golden[name] = actual
				fs.writeFileSync(FIXTURE, JSON.stringify(golden, null, '\t') + '\n')
			}
			expect(actual).toEqual(golden[name])
		},
	)
})
