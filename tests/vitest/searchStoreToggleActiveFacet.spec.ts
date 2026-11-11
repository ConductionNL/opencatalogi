/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the federation search store's
 * toggleActiveFacet: the facets it leaves, its console output in order, and
 * the search it triggers while any facet is active.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

describe('search store toggleActiveFacet (characterisation)', () => {
	let logs: unknown[][]
	let store: ReturnType<typeof useSearchStore>

	beforeEach(() => {
		setActivePinia(createPinia())
		logs = []
		vi.spyOn(console, 'log').mockImplementation((...args: unknown[]) => {
			logs.push(JSON.parse(JSON.stringify(args)))
		})
		store = useSearchStore()
		vi.spyOn(store, 'searchPublications').mockImplementation(async () => {
			logs.push(['searchPublications'])
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('adds a facet, logs it and searches', () => {
		store.toggleActiveFacet('status', 'terms', true, { size: 5 })
		expect(store.activeFacets).toEqual({ status: { type: 'terms', config: { size: 5 } } })
		expect(logs).toEqual([
			['🔧 toggleActiveFacet called with:', {
				fieldName: '"status"',
				facetType: '"terms"',
				enabled: true,
				config: { size: 5 },
				fieldNameType: 'string',
				facetTypeType: 'string',
			}],
			['✅ Added active facet:', 'status', '=', { type: 'terms', config: { size: 5 } }],
			['📋 All active facets after update:', { status: { type: 'terms', config: { size: 5 } } }],
			['🔍 Triggering search to get facet buckets for:', ['status']],
			['searchPublications'],
		])
	})

	it('defaults the config and logs non-string names as they are', () => {
		store.toggleActiveFacet(7 as unknown as string, null as unknown as string, true)
		expect(store.activeFacets).toEqual({ 7: { type: null, config: {} } })
		expect(logs[0][1]).toEqual({
			fieldName: 7,
			facetType: null,
			enabled: true,
			config: {},
			fieldNameType: 'number',
			facetTypeType: 'object',
		})
	})

	it('removes a facet with a new object and searches while others remain', () => {
		store.activeFacets = { a: { type: 'terms', config: {} }, b: { type: 'range', config: {} } }
		const before = store.activeFacets
		store.toggleActiveFacet('a', '', false)
		expect(store.activeFacets).toEqual({ b: { type: 'range', config: {} } })
		expect(store.activeFacets).not.toBe(before)
		expect(logs.slice(1)).toEqual([
			['❌ Removed active facet:', 'a'],
			['📋 All active facets after update:', { b: { type: 'range', config: {} } }],
			['🔍 Triggering search to get facet buckets for:', ['b']],
			['searchPublications'],
		])
	})

	it('does not search once the last facet is removed', () => {
		store.activeFacets = { a: { type: 'terms', config: {} } }
		store.toggleActiveFacet('a', '', false)
		expect(store.activeFacets).toEqual({})
		expect(logs.slice(1)).toEqual([
			['❌ Removed active facet:', 'a'],
			['📋 All active facets after update:', {}],
		])
	})
})
