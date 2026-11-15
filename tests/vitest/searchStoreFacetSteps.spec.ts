/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the facet steps of the TS search store: the facetable field
 * discovery request (with and without a search term, and its failure) and the
 * facet query built from the active facets.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSearchStore } from '../../src/store/modules/search.ts'

describe('search store facet steps', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'log').mockImplementation(() => {})
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('discovers facetable fields without a term, with filters and params', async () => {
		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ facetable: { a: 1 } }) }))
		const store = useSearchStore()
		store.filters = { status: 'open' }
		await store.discoverFacetableFields({ _extra: '1' })

		const url = String((globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0][0])
		const query = new URLSearchParams(url.split('?')[1])
		expect([...query.keys()]).toEqual(['_facetable', '_aggregate', '_limit', 'status', '_extra'])
		expect(store.facetable).toEqual({ a: 1 })
		expect(store.facetsLoading).toBe(false)
	})

	it('puts the term first and lets params win over the defaults', async () => {
		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }))
		const store = useSearchStore()
		store.searchTerm = 'park'
		await store.discoverFacetableFields({ _limit: '5' })

		const url = String((globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0][0])
		const query = new URLSearchParams(url.split('?')[1])
		expect([...query.entries()]).toEqual([['_search', 'park'], ['_facetable', 'true'], ['_aggregate', 'true'], ['_limit', '5']])
		expect(store.facetable).toEqual({})
	})

	it('empties the facetable fields when discovery fails', async () => {
		globalThis.fetch = vi.fn(async () => {
			throw new Error('down')
		})
		const store = useSearchStore()
		store.facetable = { old: true } as never
		await store.discoverFacetableFields()

		expect(store.facetable).toEqual({})
		expect(store.facetsLoading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Failed to discover facetable fields:', expect.any(Error))
	})

	it('builds the facet query: metadata facets under @self, object fields by name', () => {
		const store = useSearchStore()
		store.activeFacets = {
			'@self.register': { type: 'terms', config: { size: 5 } },
			theme: { type: 'terms', config: {}, extra: 'dropped' },
			'@self.created': { type: 'date_histogram', config: { interval: 'month' } },
		} as never

		const query = store.buildFacetQuery()
		expect(query).toEqual({
			'@self': {
				register: { type: 'terms', config: { size: 5 } },
				created: { type: 'date_histogram', config: { interval: 'month' } },
			},
			theme: { type: 'terms', config: {} },
		})
		expect(Object.keys(query)).toEqual(['@self', 'theme'])
	})

	it('builds an empty @self section without active facets', () => {
		expect(useSearchStore().buildFacetQuery()).toEqual({ '@self': {} })
	})
})
