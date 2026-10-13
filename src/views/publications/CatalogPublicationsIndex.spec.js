/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CatalogPublicationsIndex.vue`: the catalog it resolves
 * from the slug, the pair and endpoint it hands CnIndexPage, and where a row
 * of each schema opens.
 */

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import CatalogPublicationsIndex from './CatalogPublicationsIndex.vue'
import { navigationStore, objectStore } from '../../store/store.js'

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
 * @param {object} [options.publicationPairConfig] Props for the publication pair only.
 * @return {{wrapper: object, route: object, router: object}} The mounted page and its route doubles.
 */
function mountPage({
	slug = 'woo',
	query = {},
	register = '19',
	schema = '173',
	publicationPairConfig = undefined,
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
			publicationPairConfig,
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

	it("lists all of the catalog's pairs from its endpoint, under the page heading", async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const { wrapper } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		expect(walk).not.toHaveBeenCalled()
		expect(page.props('register')).toBe('19')
		expect(page.props('schema')).toBe('173')
		expect(page.props('collectionUrl')).toMatch(
			/\/apps\/opencatalogi\/api\/woo$/,
		)
		expect(page.props('showTitle')).toBe(true)
		expect(page.props('description')).toBe(
			'Manage your publications and their status',
		)
		expect(page.vm.$.vnode.key).toBe('woo:19:173')
		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
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

	it('opens a clicked publication on its detail page in this catalog', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const { wrapper, router } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		const row = { id: 'abc', '@self': { id: 'abc', register: 19, schema: 173 } }
		expect(page.props('rowClickToView')).toBe(true)
		const target = {
			name: 'PublicationDetail',
			params: { catalogSlug: 'woo', id: 'abc' },
		}
		expect(page.props('viewTo')(row)).toEqual(target)
		expect(page.props('viewTo')({})).toBeNull()

		page.vm.$emit('row-click', row)
		page.vm.$emit('view', row)
		page.vm.$emit('row-aux-click', row, new MouseEvent('auxclick'))

		expect(router.push).toHaveBeenCalledTimes(3)
		for (const call of router.push.mock.calls) {
			expect(call[0]).toEqual(target)
		}
	})

	it('opens a row of another schema in the form for that schema', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const { wrapper, router } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		const rule = { id: 'r1', '@self': { id: 'r1', register: 19, schema: 184 } }
		expect(page.props('viewTo')(rule)).toBeNull()

		page.vm.$emit('view', rule)
		expect(page.vm.formDialogItem).toEqual(rule)

		page.vm.formDialogItem = null
		page.vm.$emit(
			'row-aux-click',
			rule,
			new MouseEvent('auxclick', { button: 1 }),
		)
		expect(page.vm.formDialogItem).toBeNull()

		page.vm.$emit('row-click', rule)
		expect(page.vm.formDialogItem).toEqual(rule)

		page.vm.formDialogItem = null
		page.vm.$emit('row-click', rule, new MouseEvent('click', { ctrlKey: true }))
		expect(page.vm.formDialogItem).toEqual(rule)

		expect(router.push).not.toHaveBeenCalled()
	})

	it("shows the publication's own actions on publication rows only", async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const publicationPairConfig = {
			columns: ['@self.name', 'status'],
			actions: [
				'builtin:view',
				{ id: 'file-list', handler: 'openPublicationFiles' },
			],
		}
		const { wrapper } = mountPage({ publicationPairConfig })
		await flushPromises()

		const attrs = indexPage(wrapper).vm.$attrs
		expect(attrs.columns).toEqual(publicationPairConfig.columns)
		expect(attrs.actions[0]).toBe('builtin:view')
		expect(attrs.actions[1]).toMatchObject({
			id: 'file-list',
			handler: 'openPublicationFiles',
		})
		expect(
			attrs.actions[1].visible({ '@self': { register: 19, schema: 173 } }),
		).toBe(true)
		expect(
			attrs.actions[1].visible({ '@self': { register: 19, schema: 184 } }),
		).toBe(false)
		expect(attrs.publicationPairConfig).toBeUndefined()
	})

	it("keeps an action's own visible rule on publication rows", async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const publicationPairConfig = {
			actions: [
				{ id: 'hidden', visible: false },
				{ id: 'published', visible: (row) => row.status === 'published' },
			],
		}
		const { wrapper } = mountPage({ publicationPairConfig })
		await flushPromises()

		const [hidden, published] = indexPage(wrapper).vm.$attrs.actions
		const self = { register: 19, schema: 173 }
		expect(hidden.visible({ '@self': self })).toBe(false)
		expect(published.visible({ '@self': self, status: 'published' })).toBe(true)
		expect(published.visible({ '@self': self, status: 'draft' })).toBe(false)
		expect(
			published.visible({
				'@self': { register: 19, schema: 184 },
				status: 'published',
			}),
		).toBe(false)
	})

	it('uses the first pair, without publication config, for a catalog without the publication pair', async () => {
		objectStore.menuCatalogs = [catalog('code', [19], [184])]
		const { wrapper, router } = mountPage({
			slug: 'code',
			publicationPairConfig: { columns: ['title'] },
		})
		await flushPromises()

		const page = indexPage(wrapper)
		expect(page.props('schema')).toBe('184')
		expect(page.props('rowClickToView')).toBe(false)
		expect(page.props('viewTo')).toBeNull()
		expect(page.vm.$attrs.columns).toBeUndefined()

		page.vm.$emit('row-click', {
			id: 'abc',
			'@self': { register: 19, schema: 184 },
		})
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

	it('edits a publication in the publication modal and another row in its form', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173, 184])]
		const setActive = jest
			.spyOn(objectStore, 'setActiveObject')
			.mockImplementation(() => {})
		const setModal = jest
			.spyOn(navigationStore, 'setModal')
			.mockImplementation(() => {})
		const { wrapper, router } = mountPage()
		await flushPromises()

		const page = indexPage(wrapper)
		expect(page.vm.$attrs.editOpensDetail).toBe(true)
		const publication = {
			id: 'abc',
			'@self': { id: 'abc', register: 19, schema: 173 },
		}
		const rule = { id: 'r1', '@self': { id: 'r1', register: 19, schema: 184 } }

		page.vm.$emit('edit-open', publication)
		expect(setActive).toHaveBeenCalledWith('publication', publication)
		expect(setModal).toHaveBeenCalledWith('viewObject')

		page.vm.$emit('edit-open', rule)
		expect(page.vm.formDialogItem).toEqual(rule)
		expect(setModal).toHaveBeenCalledTimes(1)
		expect(router.push).not.toHaveBeenCalled()
	})

	it('opens the create publication modal from the add button', async () => {
		objectStore.menuCatalogs = [catalog('woo', [19], [173])]
		const setActive = jest
			.spyOn(objectStore, 'setActiveObject')
			.mockImplementation(() => {})
		const setModal = jest
			.spyOn(navigationStore, 'setModal')
			.mockImplementation(() => {})
		const { wrapper } = mountPage()
		await flushPromises()

		indexPage(wrapper).vm.$emit('add')

		expect(setActive).toHaveBeenCalledWith('publication', null)
		expect(setModal).toHaveBeenCalledWith('viewObject')
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
