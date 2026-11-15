/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the federation search store's searchPublications: the URL
 * it requests, the state it leaves and its console output, for a plain search,
 * a search with term, filters, ordering and facets, a facet query with every
 * shape of config value, a failed response and a thrown non-error. Run with
 * RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

const FIXTURE = path.resolve(__dirname, 'fixtures/search-publications-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'

type Store = ReturnType<typeof useSearchStore>

interface Scenario {
	setup?: (store: Store) => void
	params?: Record<string, string | number | boolean>
	fetch: () => Promise<unknown>
}

const okBody = {
	results: [{ id: 'p1' }, { id: 'p2' }],
	page: 2,
	pages: 4,
	total: 61,
	limit: 20,
	offset: 20,
	facets: { status: { buckets: [] } },
	facetable: { '@self': { register: {} } },
}

const SCENARIOS: Record<string, Scenario> = {
	plain: {
		fetch: async () => ({ ok: true, json: async () => okBody }),
	},
	termFiltersOrderingFacets: {
		setup: (store) => {
			store.searchTerm = 'park'
			store.filters = { status: 'published' }
			store.ordering = { title: 'asc', '@self.updated': 'desc' }
			store.activeFacets = {
				'@self.register': { type: 'terms', config: { size: 5 } },
				theme: { type: 'terms', config: {} },
			}
		},
		params: { _page: '3', _limit: '50', extra: true },
		fetch: async () => ({ ok: true, json: async () => ({ results: null }) }),
	},
	everyFacetConfigShape: {
		setup: (store) => {
			store.activeFacets = { theme: { type: 'terms', config: {} } }
			;(
				store as unknown as { buildFacetQuery: () => unknown }
			).buildFacetQuery = () => ({
				'@self': {
					created: {
						type: 'date_histogram',
						interval: 'month',
						size: 3,
						missing: undefined,
						nested: { a: 1 },
					},
				},
				theme: { type: 'terms', size: 10, shown: false, deep: [1] },
				nullCategory: null,
				stringCategory: 'skip me',
			})
		},
		fetch: async () => ({ ok: true, json: async () => okBody }),
	},
	notOk: {
		fetch: async () => ({ ok: false, status: 503 }),
	},
	thrownNonError: {
		fetch: async () => {
			throw 'boom'
		},
	},
}

/**
 * Turn console arguments into JSON-safe values.
 *
 * @param args The console call arguments.
 * @return The arguments, with errors reduced to their message.
 */
function plain(args: unknown[]): unknown[] {
	return args.map((arg) => (arg instanceof Error ? `Error: ${arg.message}` : arg))
}

/**
 * Run one scenario and record what searchPublications did.
 *
 * @param scenario The scenario.
 * @return The trace.
 */
async function trace(scenario: Scenario): Promise<unknown> {
	vi.restoreAllMocks()
	setActivePinia(createPinia())
	const store = useSearchStore()
	scenario.setup?.(store)
	const fetchMock = vi.fn(scenario.fetch)
	globalThis.fetch = fetchMock as unknown as typeof fetch
	const log = vi.spyOn(console, 'log').mockImplementation(() => {})
	const error = vi.spyOn(console, 'error').mockImplementation(() => {})
	await store.searchPublications(scenario.params)
	return JSON.parse(
		JSON.stringify({
			fetchCalls: fetchMock.mock.calls,
			state: {
				loading: store.loading,
				error: store.error,
				searchResults: store.searchResults,
				pagination: store.pagination,
				facets: store.facets,
				facetable: store.facetable,
			},
			log: log.mock.calls.map(plain),
			error: error.mock.calls.map(plain),
		}),
	)
}

describe('search store searchPublications (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('matches the recorded traces', async () => {
		const traces: Record<string, unknown> = {}
		for (const [name, scenario] of Object.entries(SCENARIOS)) {
			traces[name] = await trace(scenario)
		}
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(traces, null, '\t') + '\n')
		}
		expect(traces).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
