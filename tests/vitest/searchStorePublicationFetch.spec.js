/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the JS search store's publication getters: the request each
 * one sends, what it returns, and the errors and console output on a missing
 * id, a failed response and a network failure. Run with RECORD_GOLDEN=1 to
 * rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.js'

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/search-publication-fetch-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'
const ACTIONS = [
	'getPublication',
	'getPublicationUses',
	'getPublicationUsed',
	'getPublicationAttachments',
]

/**
 * Run one action and record what it did.
 *
 * @param {string} action The store action name.
 * @param {object} scenario The id and the fetch behaviour.
 * @return {Promise<object>} The trace.
 */
async function trace(action, scenario) {
	const store = useSearchStore()
	globalThis.fetch = vi.fn(scenario.fetch)
	const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	const out = {}
	try {
		out.returned = await store[action](scenario.id)
	} catch (error) {
		out.thrown = error.message
	}
	out.fetchCalls = globalThis.fetch.mock.calls
	out.consoleError = consoleError.mock.calls.map(([label, error]) => [
		label,
		error?.message,
	])
	return out
}

const SCENARIOS = {
	ok: {
		id: 'abc-1',
		fetch: async () => ({
			ok: true,
			json: async () => ({ id: 'abc-1', results: [1, 2] }),
		}),
	},
	notOk: {
		id: 'abc-2',
		fetch: async () => ({
			ok: false,
			status: 500,
			statusText: 'Server Error',
		}),
	},
	networkFailure: {
		id: 'abc-3',
		fetch: async () => {
			throw new Error('network down')
		},
	},
	missingId: { id: '', fetch: async () => ({ ok: true, json: async () => ({}) }) },
}

describe('search store publication getters (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('matches the recorded traces', async () => {
		const traces = {}
		for (const action of ACTIONS) {
			for (const [name, scenario] of Object.entries(SCENARIOS)) {
				traces[`${action}.${name}`] = await trace(action, scenario)
			}
		}
		const actual = JSON.parse(JSON.stringify(traces))
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
