/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the object store's list actions: the debounced search term,
 * paging forward and back, select-all, the column filters and the active
 * object reset.
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

describe('object store list actions', () => {
	let store
	let fetchCollection

	beforeEach(() => {
		vi.useFakeTimers()
		setActivePinia(createPinia())
		store = useObjectStore()
		fetchCollection = vi.spyOn(store, 'fetchCollection').mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('searches 500 ms after the last keystroke only', () => {
		store.setSearchTerm('publication', 'w')
		vi.advanceTimersByTime(300)
		store.setSearchTerm('publication', 'woo')

		expect(store.searchTerms.publication).toBe('woo')
		vi.advanceTimersByTime(499)
		expect(fetchCollection).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1)
		expect(fetchCollection).toHaveBeenCalledTimes(1)
		expect(fetchCollection).toHaveBeenCalledWith('publication', { _search: 'woo' })
	})

	it('searches without a term when the term is emptied', () => {
		store.setSearchTerm('publication', '')
		vi.advanceTimersByTime(500)

		expect(fetchCollection).toHaveBeenCalledWith('publication', {})
	})

	it('clears the term, cancels a pending search and reloads at once', () => {
		store.setSearchTerm('publication', 'woo')
		store.clearSearchTerm('publication')

		expect(store.searchTerms.publication).toBe('')
		expect(store.searchDebounceTimers.publication).toBeNull()
		expect(fetchCollection).toHaveBeenCalledTimes(1)
		expect(fetchCollection).toHaveBeenCalledWith('publication')

		vi.advanceTimersByTime(1000)
		expect(fetchCollection).toHaveBeenCalledTimes(1)
	})

	it('follows the next link when loading more', async () => {
		store.setPagination('publication', { page: 1, pages: 3, limit: 20, next: 'https://host/api?_page=2&_limit=20' })

		await store.loadMore('publication')

		expect(fetchCollection).toHaveBeenCalledWith('publication', { _page: '2', _limit: '20' }, true)
	})

	it('asks for the next page number without a next link, and stops at the last page', async () => {
		store.setPagination('publication', { page: 2, pages: 3, limit: 50 })
		await store.loadMore('publication')
		expect(fetchCollection).toHaveBeenCalledWith('publication', { _page: 3, _limit: 50 }, true)

		fetchCollection.mockClear()
		store.setPagination('publication', { page: 3, pages: 3, limit: 50 })
		await store.loadMore('publication')
		expect(fetchCollection).not.toHaveBeenCalled()
	})

	it('follows the prev link or the previous page number, and stops at page one', async () => {
		store.setPagination('publication', { page: 2, pages: 3, limit: 20, prev: 'https://host/api?_page=1' })
		await store.loadPrevious('publication')
		expect(fetchCollection).toHaveBeenLastCalledWith('publication', { _page: '1' }, false)

		store.setPagination('publication', { page: 3, pages: 3, limit: 50 })
		await store.loadPrevious('publication')
		expect(fetchCollection).toHaveBeenLastCalledWith('publication', { _page: 2, _limit: 50 }, false)

		fetchCollection.mockClear()
		store.setPagination('publication', { page: 1, pages: 3, limit: 50 })
		await store.loadPrevious('publication')
		expect(fetchCollection).not.toHaveBeenCalled()
	})

	it('selects every publication and deselects them when all are selected', () => {
		store.toggleSelectAllObjects()
		expect(store.selectedObjects).toEqual([])

		store.collections = { ...store.collections, publication: { results: [{ '@self': { id: 'a' } }, { id: 'b' }] } }
		store.toggleSelectAllObjects()
		expect(store.selectedObjects).toEqual(['a', 'b'])
		expect(store.isAllSelected).toBe(true)

		store.toggleSelectAllObjects()
		expect(store.selectedObjects).toEqual([])
	})

	it('builds properties from a schema and the column filters from them', () => {
		store.metadata = { title: { enabled: true } }
		store.initializeProperties({ properties: { summary: { title: 'Summary', description: 'Short' }, code: {} } })

		expect(store.properties).toEqual({
			summary: { label: 'Summary', key: 'summary', description: 'Short', enabled: false },
			code: { label: 'code', key: 'code', description: 'Property: code', enabled: false },
		})

		store.initializeColumnFilters()
		expect(store.columnFilters).toEqual({ meta_title: true, prop_summary: false, prop_code: false })

		store.initializeProperties(null)
		expect(store.properties).toEqual({})
	})

	it('switches a metadata or property column and records the filter', () => {
		store.metadata = { title: { enabled: true } }
		store.properties = { summary: { enabled: false } }

		store.updateColumnFilter('meta_title', false)
		store.updateColumnFilter('prop_summary', true)
		store.updateColumnFilter('prop_missing', true)

		expect(store.metadata.title.enabled).toBe(false)
		expect(store.properties.summary.enabled).toBe(true)
		expect(store.properties.missing).toBeUndefined()
		expect(store.columnFilters).toEqual({ meta_title: false, prop_summary: true, prop_missing: true })
	})

	it('records an error per type and logs only a real one', () => {
		const logged = vi.spyOn(console, 'error').mockImplementation(() => {})

		store.setError('publication', 'down')
		store.setError('catalog', null)

		expect(store.errors).toMatchObject({ publication: 'down', catalog: null })
		expect(logged).toHaveBeenCalledTimes(1)
		expect(logged).toHaveBeenCalledWith('Error set for type:', 'publication', 'down')
	})

	it('answers an object error or null', () => {
		store.objectErrors = { a: 'failed' }

		expect(store.getObjectError('a')).toBe('failed')
		expect(store.getObjectError('b')).toBeNull()
	})

	it('clears the active object and its related data', () => {
		store.activeObjects = { publication: { id: 'a' } }
		store.relatedData = { publication: { logs: [1], uses: [], used: [], files: [] } }

		store.clearActiveObject('publication')

		expect(store.activeObjects.publication).toBeNull()
		expect(store.relatedData.publication).toEqual({ logs: null, uses: null, used: null, files: null })
	})
})
