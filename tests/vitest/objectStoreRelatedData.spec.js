/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterization tests for the object store's fetchRelatedData: the query
 * it builds per kind of related data, the pagination it derives, what it
 * stores and how it fails.
 */

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

describe('object store fetchRelatedData', () => {
	let store
	let consoleError

	/**
	 * Make fetch answer with the given body and status.
	 *
	 * @param {object} body - The JSON body
	 * @param {boolean} ok - Whether the response is ok
	 */
	function answer(body, ok = true) {
		globalThis.fetch = vi.fn(async () => ({ ok, json: async () => body }))
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		store.settings = { objectTypes: [], configuration: {} }
		store._constructApiUrl = vi.fn(() => '/url')
		consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		consoleError.mockRestore()
	})

	it.each([
		['uses', {}, { _extend: '@self.schema' }],
		['used', { extend: 'x' }, { extend: 'x', _extend: 'x' }],
		['uses', { _extend: 'y', extend: 'x' }, { _extend: 'y', extend: 'x' }],
		['files', { _limit: 5 }, { _limit: 5 }],
		['logs', {}, {}],
	])('builds the %s query from %j', async (dataType, params, expected) => {
		answer({})

		await store.fetchRelatedData('publication', 'p1', dataType, params, {
			pub: 1,
		})

		expect(store._constructApiUrl).toHaveBeenCalledWith(
			'publication',
			'p1',
			dataType,
			expected,
			{ pub: 1 },
		)
	})

	it.each([
		[
			'files',
			{},
			{ total: 1001 },
			{ total: 1001, page: 1, pages: 3, limit: 500, next: null, prev: null },
		],
		[
			'uses',
			{},
			{ total: 41 },
			{ total: 41, page: 1, pages: 3, limit: 20, next: null, prev: null },
		],
		[
			'uses',
			{ limit: 10 },
			{ page: 2 },
			{ total: 0, page: 2, pages: 0, limit: 10, next: null, prev: null },
		],
		[
			'uses',
			{ _limit: 7, limit: 10 },
			{ total: 15, limit: '4', pages: 9, next: 'n', prev: 'p' },
			{ total: 15, page: 1, pages: 9, limit: 4, next: 'n', prev: 'p' },
		],
	])('derives the %s pagination', async (dataType, params, data, expected) => {
		answer(data)

		await store.fetchRelatedData('publication', 'p1', dataType, params)

		expect(store.pagination[`publication_${dataType}`]).toEqual(expected)
	})

	it('stores logs as their results and anything else whole, without pagination when the answer has none', async () => {
		answer({ results: [{ id: 'l' }] })
		await store.fetchRelatedData('publication', 'p1', 'logs')
		answer({ items: 1 })
		await store.fetchRelatedData('publication', 'p1', 'uses')

		expect(store.relatedData.publication).toEqual({
			logs: [{ id: 'l' }],
			uses: { items: 1 },
		})
		expect(store.pagination.publication_logs).toBeUndefined()
		expect(store.pagination.publication_uses).toBeUndefined()
	})

	it('records the failure and throws it on', async () => {
		answer({}, false)

		await expect(
			store.fetchRelatedData('publication', 'p1', 'files'),
		).rejects.toThrow('Failed to fetch files for publication')
		expect(consoleError).toHaveBeenCalled()
	})
})
