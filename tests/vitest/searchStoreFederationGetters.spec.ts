/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the federation search store's GET actions and clearSearch:
 * the request each sends, what it returns or throws, its console output and
 * the state it leaves, for a good response, a failed response and a network
 * failure. Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/search-federation-getters-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'

type Store = ReturnType<typeof useSearchStore>

const FETCHES: Record<string, () => Promise<unknown>> = {
	ok: async () => ({
		ok: true,
		json: async () => ({
			id: 'abc-1',
			facetable: { '@self': { register: {} } },
		}),
	}),
	failed: async () => ({ ok: false, status: 503, json: async () => ({}) }),
	network: async () => {
		throw new Error('offline')
	},
}

const ACTIONS: Record<string, (store: Store) => Promise<unknown> | unknown> = {
	getPublication: (store) => store.getPublication('abc-1'),
	getPublicationUses: (store) =>
		store.getPublicationUses('abc-1', { _limit: 5, _search: 'tree' }),
	getPublicationUsed: (store) => store.getPublicationUsed('abc-1', { _page: 2 }),
	discoverFacetableFields: (store) => {
		store.searchTerm = 'park'
		store.filters = { status: 'open' }
		return store.discoverFacetableFields({ _extra: '1' })
	},
	clearSearch: (store) => {
		store.searchTerm = 'park'
		store.filters = { status: 'open' }
		store.ordering = { title: 'asc' }
		store.searchResults = [{ id: 'p1' }]
		store.facets = { a: 1 }
		store.facetable = { b: 2 }
		store.activeFacets = { c: 3 }
		store.error = 'x'
		store.selectedPublications = ['p1']
		return store.clearSearch()
	},
}

/**
 * Run one action against one fetch behaviour and record what it did.
 *
 * @param action The action name.
 * @param fetchName The fetch behaviour name.
 * @return The trace.
 */
async function trace(action: string, fetchName: string) {
	const store = useSearchStore()
	const fetchMock = vi.fn(FETCHES[fetchName])
	globalThis.fetch = fetchMock as unknown as typeof fetch
	const log = vi.spyOn(console, 'log').mockImplementation(() => {})
	const error = vi.spyOn(console, 'error').mockImplementation(() => {})
	const out: Record<string, unknown> = {}
	try {
		out.returned = (await ACTIONS[action](store)) ?? null
	} catch (e) {
		out.thrown = (e as Error).message
	}
	out.fetchCalls = fetchMock.mock.calls
	out.consoleLog = log.mock.calls
	out.consoleError = error.mock.calls.map(([label, e]) => [
		label,
		(e as Error)?.message,
	])
	out.state = JSON.parse(JSON.stringify(store.$state))
	return out
}

describe('search store federation getters (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('matches the recorded traces', async () => {
		const traces: Record<string, unknown> = {}
		for (const action of Object.keys(ACTIONS)) {
			for (const fetchName of Object.keys(FETCHES)) {
				setActivePinia(createPinia())
				traces[`${action}/${fetchName}`] = await trace(action, fetchName)
				vi.restoreAllMocks()
			}
		}
		if (RECORD) {
			fs.writeFileSync(FIXTURE, JSON.stringify(traces, null, '\t') + '\n')
		}
		expect(traces).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
