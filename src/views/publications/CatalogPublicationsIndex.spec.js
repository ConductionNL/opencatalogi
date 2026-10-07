/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CatalogPublicationsIndex.vue`: the catalog it resolves
 * from the slug, the register/schema pair it hands CnIndexPage, the selector
 * for catalogs with several pairs, and where a row opens.
 */

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import CatalogPublicationsIndex from './CatalogPublicationsIndex.vue'
import { objectStore } from '../../store/store.js'

/**
 * A menu entry as the store keeps it.
 *
 * @param {string} slug The catalog slug.
 * @param {Array<number>} registers The register ids.
 * @param {Array<number>} schemas The schema ids.
 * @return {object} The entry.
 */
function catalog(slug, registers, schemas) {
	return { id: `id-${slug}`, slug, title: `Title ${slug}`, registers, schemas }
}

/**
 * Mount the page the way CnPageRenderer does: config and route params as
 * props and attrs.
 *
 * @param {object} [options] Mount options.
 * @param {string} [options.slug] The route's catalog slug.
 * @param {object} [options.query] The route query.
 * @return {{wrapper: object, route: object, router: object}} The mounted page and its route doubles.
 */
function mountPage({
	slug = 'woo',
	query = {},
	register = '19',
	schema = '173',
} = {}) {
	const route = reactive({ params: { catalogSlug: slug }, query })
	const router = {
		push: jest.fn((location) => {
			if (location.query) {
				route.query = location.query
			}
			return Promise.resolve()
		}),
	}
	const wrapper = mount(CatalogPublicationsIndex, {
		props: {
			catalogSlug: slug,
			register,
			schema,
			actionToggles: { showAdd: true, selectable: true },
			documentationUrl: 'https://example.org/docs',
			onRowClick: jest.fn(),
		},
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (match, key) => vars[key] ?? match),
				$route: route,
				$router: router,
			},
		},
	})
	return { wrapper, route, router }
}

const indexPage = (wrapper) => wrapper.findComponent({ name: 'CnIndexPage' })
const select = (wrapper) => wrapper.findComponent({ name: 'NcSelect' })

// A page left mounted would react to the next test's menu list.
enableAutoUnmount(afterEach)

describe('CatalogPublicationsIndex', () => {
	let walk
	let lookup

	beforeEach(() => {
		objectStore.menuCatalogs = []
		walk = jest.spyOn(objectStore, 'fetchMenuCatalogs').mockResolvedValue()
		lookup = jest
			.spyOn(objectStore, 'fetchMenuCatalogBySlug')
			.mockResolvedValue(null)
		global.fetch = jest.fn(() => Promise.resolve({ ok: false }))
	})

	afterEach(() => {
		jest.restoreAllMocks()
		delete global.fetch
	})

	it('says the catalog was not found for an unknown slug', async () => {
		const { wrapper } = mountPage({ slug: 'nope' })
		await flushPromises()

		expect(walk).toHaveBeenCalledWith(0)
		expect(lookup).toHaveBeenCalledWith('nope')
		expect(indexPage(wrapper).exists()).toBe(false)
		expect(wrapper.text()).toContain('Catalog not found')
	})

	it('shows a loading state while the catalog list loads on a deep link', async () => {
		let release
		walk.mockReturnValue(
			new Promise((resolve) => {
				release = resolve
			}),
		)
		const { wrapper } = mountPage()
		await flushPromises()

		expect(wrapper.find('.nc-loading-icon-stub').exists()).toBe(true)

		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		release()
		await flushPromises()

		expect(lookup).not.toHaveBeenCalled()
		expect(indexPage(wrapper).props('schema')).toBe('173')
	})

	it('lists a catalog the menu list lacks, found by the slug lookup', async () => {
		lookup.mockResolvedValue(catalog('woo', [19], [184]))
		const { wrapper } = mountPage()
		await flushPromises()

		expect(indexPage(wrapper).props('register')).toBe('19')
		expect(indexPage(wrapper).props('schema')).toBe('184')
	})

	it('says when the catalog lookup fails', async () => {
		lookup.mockRejectedValue(new Error('offline'))
		jest.spyOn(console, 'warn').mockImplementation(() => {})
		const { wrapper } = mountPage()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not load the catalog')
	})

	it('points a catalog without numeric registers or schemas to its detail page', async () => {
		objectStore.menuCatalogs = [catalog('woo', [], [173])]
		const { wrapper } = mountPage()
		await flushPromises()

		expect(indexPage(wrapper).exists()).toBe(false)
		expect(wrapper.text()).toContain(
			'This catalog has no registers or schemas configured',
		)
		expect(wrapper.findComponent({ name: 'NcButton' }).props('to')).toEqual({
			name: 'CatalogDetail',
			params: { id: 'id-woo' },
		})
	})

	it('lists the single pair of a catalog without a selector', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const { wrapper } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		expect(walk).not.toHaveBeenCalled()
		expect(page.props('register')).toBe('19')
		expect(page.props('schema')).toBe('173')
		expect(page.props('description')).toBe('Title woo')
		expect(page.vm.$.vnode.key).toBe('woo:19:173')
		expect(select(wrapper).exists()).toBe(false)
	})

	it('passes the action toggles and the rest of the config through', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const { wrapper } = mountPage()
		await flushPromises()

		const attrs = indexPage(wrapper).vm.$attrs
		expect(attrs.showAdd).toBe(true)
		expect(attrs.selectable).toBe(true)
		expect(attrs.documentationUrl).toBe('https://example.org/docs')
		expect(attrs.actionToggles).toBeUndefined()
		expect(attrs.onRowClick).toBeUndefined()
	})

	it('opens a clicked row on its publication detail page in this catalog', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const { wrapper, router } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		expect(page.props('rowClickToView')).toBe(true)
		const target = {
			name: 'PublicationDetail',
			params: { catalogSlug: 'woo', id: 'abc' },
		}
		expect(page.props('viewTo')({ '@self': { id: 'abc' } })).toEqual(target)
		expect(page.props('viewTo')({})).toBeNull()

		page.vm.$emit('row-click', { id: 'abc' })
		page.vm.$emit('view', { id: 'abc' })
		page.vm.$emit('row-aux-click', { id: 'abc' }, new MouseEvent('auxclick'))
		page.vm.$emit('edit-open', { id: 'abc' })

		expect(router.push).toHaveBeenCalledTimes(4)
		for (const call of router.push.mock.calls) {
			expect(call[0]).toEqual(target)
		}
		expect(page.vm.$attrs.showViewAction).toBeUndefined()
	})

	it('leaves rows of another pair to CnIndexPage: a click selects, no View', async () => {
		objectStore.menuCatalogs = [catalog('code', [19], [184])]
		const { wrapper, router } = mountPage({ slug: 'code' })
		await flushPromises()

		const page = indexPage(wrapper)
		expect(page.props('schema')).toBe('184')
		expect(page.props('rowClickToView')).toBe(false)
		expect(page.props('viewTo')).toBeNull()
		expect(page.vm.$attrs.showViewAction).toBe(false)
		expect(page.vm.$attrs.showEditAction).toBeUndefined()
		expect(wrapper.vm.rowTarget({ id: 'abc' })).toBeNull()

		page.vm.$emit('row-click', { id: 'abc' })
		page.vm.$emit('view', { id: 'abc' })

		expect(router.push).not.toHaveBeenCalled()
	})

	it('opens no detail page while the publication ids are unresolved', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const { wrapper } = mountPage({
			register: '@resolve:publication_register',
			schema: '@resolve:publication_schema',
		})
		await flushPromises()

		expect(indexPage(wrapper).props('rowClickToView')).toBe(false)
		expect(indexPage(wrapper).props('viewTo')).toBeNull()
	})

	it('offers a selector for several pairs and keeps the choice in the query', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const { wrapper, router } = mountPage()
		await flushPromises()

		expect(indexPage(wrapper).props('schema')).toBe('173')
		const options = select(wrapper).props('options')
		expect(options.map((option) => option.key)).toEqual(['19-173', '19-184'])
		expect(select(wrapper).props('modelValue').key).toBe('19-173')

		select(wrapper).vm.$emit('update:modelValue', options[1])
		await flushPromises()

		expect(router.push).toHaveBeenCalledWith({ query: { _pair: '19-184' } })
		expect(indexPage(wrapper).props('schema')).toBe('184')
		expect(indexPage(wrapper).vm.$.vnode.key).toBe('woo:19:184')
	})

	it('starts on the pair the query names', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const { wrapper } = mountPage({ query: { _pair: '19-184' } })
		await flushPromises()

		expect(indexPage(wrapper).props('schema')).toBe('184')
	})

	it('labels the pairs with the register and schema titles', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const titles = {
			'registers/19': 'Publication',
			'schemas/173': 'Publication',
			'schemas/184': 'Public code component',
		}
		global.fetch = jest.fn((url) => {
			const key = Object.keys(titles).find((path) => url.endsWith(path))
			return Promise.resolve({
				ok: Boolean(key),
				json: () => Promise.resolve({ title: titles[key] }),
			})
		})
		const { wrapper } = mountPage()
		await flushPromises()

		expect(
			select(wrapper)
				.props('options')
				.map((option) => option.label),
		).toEqual([
			'Publication in Publication',
			'Public code component in Publication',
		])
	})

	it('re-resolves when the catalog slug changes', async () => {
		objectStore.menuCatalogs = [
			catalog('woo', [19], [173]),
			catalog('code', [19], [184]),
		]
		const { wrapper } = mountPage()
		await flushPromises()
		expect(indexPage(wrapper).vm.$.vnode.key).toBe('woo:19:173')

		await wrapper.setProps({ catalogSlug: 'code' })
		await flushPromises()

		expect(indexPage(wrapper).props('schema')).toBe('184')
		expect(indexPage(wrapper).vm.$.vnode.key).toBe('code:19:184')

		await wrapper.setProps({ catalogSlug: 'gone' })
		await flushPromises()

		expect(lookup).toHaveBeenCalledWith('gone')
		expect(wrapper.text()).toContain('Catalog not found')
	})

	it('looks a catalog up again when the menu list reloads', async () => {
		lookup.mockResolvedValue(catalog('woo', [19], [173]))
		const { wrapper } = mountPage()
		await flushPromises()
		expect(indexPage(wrapper).props('schema')).toBe('173')

		// Its scope was edited.
		lookup.mockResolvedValue(catalog('woo', [19], [184]))
		objectStore.menuCatalogs = [catalog('other', [19], [173])]
		await flushPromises()

		expect(walk).toHaveBeenCalledTimes(1)
		expect(lookup).toHaveBeenCalledTimes(2)
		expect(indexPage(wrapper).props('schema')).toBe('184')

		// It was deleted.
		lookup.mockResolvedValue(null)
		objectStore.menuCatalogs = []
		await flushPromises()

		expect(indexPage(wrapper).exists()).toBe(false)
		expect(wrapper.text()).toContain('Catalog not found')
	})

	it('discards a lookup that resolves after a slug switch', async () => {
		let releaseLookup
		lookup.mockReturnValue(
			new Promise((resolve) => {
				releaseLookup = resolve
			}),
		)
		const { wrapper } = mountPage({ slug: 'old' })
		await flushPromises()
		expect(lookup).toHaveBeenCalledWith('old')

		objectStore.menuCatalogs = [catalog('code', [19], [184])]
		await wrapper.setProps({ catalogSlug: 'code' })
		await flushPromises()
		releaseLookup(catalog('old', [19], [173]))
		await flushPromises()

		expect(wrapper.vm.lookedUpCatalog).toBeNull()
		expect(indexPage(wrapper).props('schema')).toBe('184')
		expect(indexPage(wrapper).vm.$.vnode.key).toBe('code:19:184')
	})
})
