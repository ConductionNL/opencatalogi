/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CatalogNavigation.vue`: it forwards CnAppRoot's `#menu`
 * slot bindings to CnAppNav unchanged, with the catalog entries added to the
 * manifest.
 */

import { mount } from '@vue/test-utils'
import { toRaw } from 'vue'
import CatalogNavigation from './CatalogNavigation.vue'
import { objectStore } from '../store/store.js'

/**
 * A menu manifest in the shape CnAppRoot hands its `#menu` slot.
 *
 * @return {object} A fresh manifest.
 */
function slotManifest() {
	return {
		version: '1.0.0',
		menu: [
			{ id: 'Dashboard', label: 'Dashboard', route: 'Dashboard', order: 10 },
			{ id: 'Search', label: 'Search', route: 'Search', order: 30 },
		],
		pages: [],
	}
}

/**
 * Mount the wrapper with the slot bindings CnAppRoot passes.
 *
 * @param {object} manifest The slot's manifest.
 * @return {object} The CnAppNav stub's wrapper.
 */
function mountNav(manifest) {
	const wrapper = mount(CatalogNavigation, {
		props: {
			manifest,
			permissions: ['admin'],
			isOwner: true,
			isAdmin: true,
			appId: 'opencatalogi',
		},
	})
	return wrapper.findComponent({ name: 'CnAppNav' })
}

describe('CatalogNavigation', () => {
	afterEach(() => {
		objectStore.menuCatalogs = []
	})

	it('passes every slot binding through to CnAppNav', () => {
		const nav = mountNav(slotManifest())

		expect(nav.exists()).toBe(true)
		expect(nav.props('permissions')).toEqual(['admin'])
		expect(nav.props('isOwner')).toBe(true)
		expect(nav.props('isAdmin')).toBe(true)
		expect(nav.props('appId')).toBe('opencatalogi')
	})

	it('hands CnAppNav the same manifest reference when there are no catalogs', () => {
		const manifest = slotManifest()
		const nav = mountNav(manifest)

		// The mount wraps props in a reactive proxy; compare the raw objects.
		expect(toRaw(nav.props('manifest'))).toBe(manifest)
	})

	it('hands CnAppNav the manifest with one entry per catalog', () => {
		objectStore.menuCatalogs = [{ id: '1', slug: 'woo', title: 'Woo' }]
		const manifest = slotManifest()
		const nav = mountNav(manifest)

		expect(nav.props('manifest')).not.toBe(manifest)
		expect(nav.props('manifest').menu.map((item) => item.id)).toEqual([
			'Dashboard',
			'Search',
			'catalog-woo',
		])
		expect(manifest.menu).toHaveLength(2)
	})

	it('leaves CnAppNav on its injected translator', () => {
		objectStore.menuCatalogs = [{ id: '1', slug: 'woo', title: 'Woo' }]
		const nav = mountNav(slotManifest())

		expect(nav.props('translate')).toBeNull()
	})
})
