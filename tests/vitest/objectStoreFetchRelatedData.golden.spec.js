/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the object store's fetchRelatedData: the URL arguments,
 * every store action it runs with its arguments, the related data and
 * pagination it leaves, its console output and what it throws, for each kind
 * of related data, answers with and without pagination fields, logs with and
 * without results, a second fetch for the same type, missing settings, a
 * failed response and a thrown error. Run with RECORD_GOLDEN=1 to rewrite
 * the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/object-fetch-related-data-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'

/**
 * A fetch that answers with the given body.
 *
 * @param {object} body The JSON body.
 * @param {boolean} ok Whether the response is ok.
 * @return {Function} The fetch stand-in.
 */
function answer(body, ok = true) {
	return async () => ({ ok, json: async () => body })
}

const SCENARIOS = {
	usesWithPagination: {
		calls: [['publication', 'p1', 'uses', {}, { pub: 1 }]],
		fetch: answer({ total: 41, results: [1] }),
	},
	usedPageOnly: {
		calls: [['publication', 'p1', 'used', { extend: 'x' }]],
		fetch: answer({ page: 2 }),
	},
	filesNoPagination: {
		calls: [['catalog', 'c1', 'files', { _limit: 5 }]],
		fetch: answer({ items: [1, 2] }),
	},
	logsWithResults: {
		calls: [['publication', 'p2', 'logs']],
		fetch: answer({ results: [{ id: 'l' }], total: 1 }),
	},
	logsWithoutResults: {
		calls: [['publication', 'p2', 'logs']],
		fetch: answer({}),
	},
	logsNullResults: {
		calls: [['publication', 'p2', 'logs']],
		fetch: answer({ results: null, page: 1 }),
	},
	twoKindsSameType: {
		calls: [
			['publication', 'p1', 'logs'],
			['publication', 'p1', 'uses', { limit: 10 }],
		],
		fetch: answer({ total: 0 }),
	},
	noSettings: {
		noSettings: true,
		calls: [['publication', 'p1', 'uses']],
		fetch: answer({ results: [] }),
	},
	notOk: { calls: [['publication', 'p1', 'files']], fetch: answer({}, false) },
	throws: {
		calls: [['publication', 'p1', 'used']],
		fetch: async () => {
			throw new Error('network down')
		},
	},
	totalUndefinedPageNull: {
		calls: [['publication', 'p1', 'uses']],
		fetch: answer({ page: null }),
	},
}

/**
 * Run one scenario and record what the store did.
 *
 * @param {object} scenario The calls and the fetch behaviour.
 * @return {Promise<object>} The trace.
 */
async function trace(scenario) {
	const store = useObjectStore()
	store.settings = scenario.noSettings
		? null
		: { objectTypes: [], configuration: {} }
	store.fetchSettings = vi.fn(async () => {
		store.settings = { fetched: true }
	})
	store._constructApiUrl = vi.fn(() => '/url')
	const actions = []
	store.$onAction(({ name, args }) => {
		actions.push([name, JSON.parse(JSON.stringify(args ?? null))])
	})
	globalThis.fetch = vi.fn(scenario.fetch)
	const error = vi.spyOn(console, 'error').mockImplementation(() => {})
	const out = { results: [] }
	for (const call of scenario.calls) {
		try {
			out.results.push({
				returned: (await store.fetchRelatedData(...call)) ?? null,
			})
		} catch (e) {
			out.results.push({ thrown: String(e) })
		}
	}
	out.fetchSettings = store.fetchSettings.mock.calls.length
	out.urlArgs = store._constructApiUrl.mock.calls
	out.fetchCalls = globalThis.fetch.mock.calls
	out.actions = actions
	out.error = error.mock.calls.map(([label, e]) => [label, String(e)])
	out.state = {
		relatedData: store.relatedData,
		pagination: store.pagination,
		loading: store.loading,
		success: store.success,
		errors: store.errors,
	}
	return out
}

describe('object store fetchRelatedData (golden)', () => {
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
