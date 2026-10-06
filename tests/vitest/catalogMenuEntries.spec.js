/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the per-catalog navigation entries
 * (src/navigation/catalogMenuEntries.js): the entry shape CnAppNav renders,
 * which catalogs get an entry, where the entries sort, and that the manifest
 * handed in is never mutated.
 */

import { describe, expect, it } from 'vitest'
import bundledManifest from '../../src/manifest.json'
import {
	CATALOG_MENU_ORDER,
	catalogMenuEntries,
	withCatalogEntries,
} from '../../src/navigation/catalogMenuEntries.js'

/**
 * A small menu manifest in the shape CnAppRoot hands its `#menu` slot.
 *
 * @return {object} A fresh manifest.
 */
function manifest() {
	return {
		version: '1.0.0',
		menu: [
			{ id: 'Dashboard', label: 'Dashboard', route: 'Dashboard', order: 10 },
			{ id: 'Search', label: 'Search', route: 'Search', order: 30 },
			{
				id: 'AdminGroup',
				label: 'Administration',
				order: 80,
				children: [{ id: 'SettingsMenu', label: 'Settings', order: 99 }],
			},
		],
		pages: [],
	}
}

describe('catalogMenuEntries', () => {
	it('builds one Publications entry per catalog, keyed by slug', () => {
		expect(catalogMenuEntries([{ id: 'a1', slug: 'woo', title: 'Woo publications' }])).toEqual([
			{
				id: 'catalog-woo',
				label: 'Woo publications',
				translateLabel: false,
				icon: 'DatabaseEyeOutline',
				route: 'Publications',
				params: { catalogSlug: 'woo' },
				order: CATALOG_MENU_ORDER,
			},
		])
	})

	it('skips catalogs without a slug', () => {
		const entries = catalogMenuEntries([
			{ slug: '', title: 'Empty slug' },
			{ title: 'No slug' },
			null,
			{ slug: 'kept', title: 'Kept' },
		])
		expect(entries.map((entry) => entry.id)).toEqual(['catalog-kept'])
	})

	it('marks every label as user data that is shown untranslated, even one equal to a built-in label', () => {
		const entries = catalogMenuEntries([
			{ slug: 'search-catalog', title: 'Search' },
			{ slug: 'no-title' },
		])
		expect(entries.map((entry) => [entry.label, entry.translateLabel])).toEqual([
			['Search', false],
			['no-title', false],
		])
	})

	it('falls back to the slug when the title is missing or blank', () => {
		const entries = catalogMenuEntries([
			{ slug: 'no-title' },
			{ slug: 'blank-title', title: '   ' },
		])
		expect(entries.map((entry) => entry.label)).toEqual(['no-title', 'blank-title'])
	})

	it('keeps the first catalog of a duplicated slug and the server order', () => {
		const entries = catalogMenuEntries([
			{ slug: 'b', title: 'B' },
			{ slug: 'a', title: 'A' },
			{ slug: 'b', title: 'B again' },
		])
		expect(entries.map((entry) => entry.label)).toEqual(['B', 'A'])
	})

	it('returns no entries for a missing list', () => {
		expect(catalogMenuEntries(undefined)).toEqual([])
	})
})

describe('catalog entry order', () => {
	it('sorts between Dashboard and Search in the bundled manifest', () => {
		const order = (id) => bundledManifest.menu.find((item) => item.id === id).order
		expect(CATALOG_MENU_ORDER).toBeGreaterThan(order('Dashboard'))
		expect(CATALOG_MENU_ORDER).toBeLessThan(order('Search'))
	})

	it('lands directly after Dashboard, in server order, once the menu is sorted', () => {
		const result = withCatalogEntries(manifest(), [
			{ slug: 'z', title: 'Z' },
			{ slug: 'a', title: 'A' },
		])
		const sorted = [...result.menu].sort((x, y) => x.order - y.order)
		expect(sorted.map((item) => item.id)).toEqual([
			'Dashboard',
			'catalog-z',
			'catalog-a',
			'Search',
			'AdminGroup',
		])
	})
})

describe('withCatalogEntries', () => {
	it('returns the same manifest reference when there are no entries', () => {
		const input = manifest()
		expect(withCatalogEntries(input, [])).toBe(input)
		expect(withCatalogEntries(input, [{ title: 'No slug' }])).toBe(input)
	})

	it('appends the entries without mutating the input', () => {
		const input = manifest()
		const snapshot = JSON.parse(JSON.stringify(input))
		const result = withCatalogEntries(input, [{ slug: 'woo', title: 'Woo' }])

		expect(result).not.toBe(input)
		expect(result.menu).toHaveLength(4)
		expect(result.menu.at(-1).id).toBe('catalog-woo')
		expect(result.pages).toBe(input.pages)
		expect(input).toEqual(snapshot)
	})

	it('handles a manifest without a menu', () => {
		expect(withCatalogEntries({}, [{ slug: 'woo' }]).menu.map((item) => item.id)).toEqual(['catalog-woo'])
	})
})
