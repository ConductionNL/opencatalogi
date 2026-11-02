/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the object store's per-object actions (deleteObject,
 * publishObject, depublishObject, validateObject, lockObject, unlockObject):
 * the request each sends, what it returns or throws, the loading and error
 * keys it sets, and how it updates the active publication and the selection.
 * Run with RECORD_GOLDEN=1 to rewrite the fixture.
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
	'fixtures/object-store-object-actions-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'

const ITEM = { id: 'o1', '@self': { register: 7, schema: { id: 9 } } }
const SELF_ID_ITEM = { '@self': { id: 'o1', register: '7', schema: '9' } }

/**
 * A fetch that answers with the updated object.
 *
 * @return {Promise<object>} The response
 */
async function ok() {
	return { ok: true, json: async () => ({ id: 'o1', updated: true }) }
}

/**
 * A fetch that answers 409.
 *
 * @return {Promise<object>} The response
 */
async function notOk() {
	return { ok: false, status: 409, statusText: 'Conflict' }
}

/**
 * A fetch that fails on the network.
 *
 * @return {Promise<never>} Always rejects
 */
async function network() {
	throw new Error('offline')
}

const ITEMS = {
	item: ITEM,
	selfId: SELF_ID_ITEM,
	noSchema: { id: 'o1', '@self': { register: 7 } },
	badIds: { id: 'o1', register: {}, schema: {} },
}

const CASES = {
	publishObject: [[]],
	depublishObject: [[]],
	unlockObject: [[]],
	validateObject: [[]],
	lockObject: [[], ['review'], [null, 600], ['review', 600]],
	deleteObject: [[]],
}

/**
 * Run one action on a store primed with an active publication and a
 * selection, and record what it did.
 *
 * @param {string} action The action name.
 * @param {object} run The item, extra arguments and fetch behaviour.
 * @return {Promise<object>} The trace.
 */
async function trace(action, run) {
	vi.restoreAllMocks()
	setActivePinia(createPinia())
	const store = useObjectStore()
	store.activeObjects = { publication: { id: 'o1' }, catalog: { id: 'c1' } }
	store.selectedObjects = [{ id: 'o1' }, { '@self': { id: 'o2' } }]
	store.objects = { publication: { legacy: ITEM } }
	const fetchMock = vi.fn(run.fetch)
	globalThis.fetch = fetchMock
	const calls = []
	vi.spyOn(store, 'setLoading').mockImplementation((key, value) =>
		calls.push(['loading', key, value]),
	)
	vi.spyOn(store, 'setError').mockImplementation((key, value) =>
		calls.push(['error', key, value]),
	)
	vi.spyOn(store, 'saveObject').mockImplementation(async (item, opts) => {
		calls.push(['saveObject', item, opts])
		if (run.fetch === notOk) {
			throw new Error('save failed')
		}
		return { data: { id: 'o1', saved: true } }
	})
	const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	const out = {}
	try {
		out.returned = await store[action](run.item, ...run.args)
	} catch (error) {
		out.thrown = error.message
	}
	return JSON.parse(
		JSON.stringify({
			...out,
			fetchCalls: fetchMock.mock.calls,
			calls,
			activeObjects: store.activeObjects,
			selectedObjects: store.selectedObjects,
			consoleError: consoleError.mock.calls.map(([label, error]) => [
				label,
				error?.message,
			]),
		}),
	)
}

describe('object store per-object actions (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('matches the recorded traces', async () => {
		const traces = {}
		const fetches = { ok, notOk, network }
		for (const [action, argSets] of Object.entries(CASES)) {
			for (const args of argSets) {
				for (const [itemName, item] of Object.entries(ITEMS)) {
					for (const [fetchName, fetch] of Object.entries(fetches)) {
						const key = [
							action,
							JSON.stringify(args),
							itemName,
							fetchName,
						].join(' ')
						traces[key] = await trace(action, { item, args, fetch })
					}
				}
			}
		}
		traces['deleteObject byType'] = await trace('deleteObject', {
			item: 'publication',
			args: ['legacy'],
			fetch: ok,
		})
		traces['deleteObject byType missing'] = await trace('deleteObject', {
			item: 'publication',
			args: ['gone'],
			fetch: ok,
		})
		traces['deleteObject null'] = await trace('deleteObject', {
			item: null,
			args: [],
			fetch: ok,
		})
		traces['publishObject other active'] = await trace('publishObject', {
			item: { id: 'o3', register: 7, schema: 9 },
			args: [],
			fetch: ok,
		})
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(traces, null, '\t') + '\n')
		}
		expect(traces).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
