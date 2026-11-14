/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for what `DashboardSideBar.vue` loads and creates: the lists it
 * fetches on mount, the catalog and publication type choices, the quick
 * publication it creates with the chosen catalog and type, and where viewing
 * a publication from the attention list leads.
 */

import DashboardSideBar from './DashboardSideBar.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/UseFileSelection.js', () => {
	const mockFiles = { value: null }
	return {
		mockFiles,
		useFileSelection() {
			return { files: mockFiles, reset: jest.fn() }
		},
	}
})
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		getCollection: jest.fn(),
		fetchCollection: jest.fn(),
		createObject: jest.fn(),
		setActiveObject: jest.fn(),
	},
}))

const { mockFiles } = jest.requireMock('../../composables/UseFileSelection.js')

/**
 * Settle every pending promise callback.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 8; i++) {
		await Promise.resolve()
	}
}

/**
 * A component context in its initial state, with every method bound to it,
 * as Vue binds them.
 *
 * @param {object} state Fields to override.
 * @return {object} The context.
 */
function vm(state = {}) {
	const ctx = { ...DashboardSideBar.data(), ...state }
	for (const [name, fn] of Object.entries(DashboardSideBar.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	mockFiles.value = null
	objectStore.fetchCollection.mockResolvedValue({ data: { results: [] } })
})

afterEach(() => {
	jest.useRealTimers()
	console.error.mockRestore()
})

describe('DashboardSideBar on mount', () => {
	it('fetches the attention lists, the catalogs and the publication types', () => {
		const ctx = vm({ fetchCatalogi: jest.fn(), fetchPublicationType: jest.fn() })

		DashboardSideBar.mounted.call(ctx)

		expect(objectStore.fetchCollection.mock.calls).toEqual([['publication'], ['attachment']])
		expect(ctx.fetchCatalogi).toHaveBeenCalledTimes(1)
		expect(ctx.fetchPublicationType).toHaveBeenCalledTimes(1)
	})

	it('starts with an empty form and nothing chosen', () => {
		expect(DashboardSideBar.data()).toMatchObject({
			catalogiLoading: false,
			catalogiList: [],
			catalogi: { options: [], value: null },
			publicationTypeLoading: false,
			publicationTypeList: [],
			publicationType: {},
			loading: false,
			error: null,
			success: null,
		})
	})
})

describe('DashboardSideBar fetchCatalogi', () => {
	it('offers every catalog by id and title, with none chosen', async () => {
		const catalogs = [{ id: 'c1', title: 'Woo' }, { id: 'c2', title: 'Raad' }]
		objectStore.fetchCollection.mockResolvedValue({ data: catalogs })
		const ctx = vm({ catalogi: { options: [], value: { id: 'old' } } })

		ctx.fetchCatalogi()
		expect(ctx.catalogiLoading).toBe(true)
		await flush()

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('catalogus')
		expect(ctx.catalogiList).toBe(catalogs)
		expect(ctx.catalogi).toEqual({ options: [{ id: 'c1', label: 'Woo' }, { id: 'c2', label: 'Raad' }], value: null })
		expect(ctx.catalogiLoading).toBe(false)
	})

	it('stops loading and keeps the choices when the fetch fails', async () => {
		objectStore.fetchCollection.mockRejectedValue(new Error('offline'))
		const ctx = vm({ catalogi: { options: [{ id: 'c1', label: 'Woo' }], value: null } })

		ctx.fetchCatalogi()
		await flush()

		expect(ctx.catalogiLoading).toBe(false)
		expect(ctx.catalogi.options).toEqual([{ id: 'c1', label: 'Woo' }])
		expect(console.error).toHaveBeenCalledWith(new Error('offline'))
	})
})

describe('DashboardSideBar fetchPublicationType', () => {
	it('keeps the full publication type list', async () => {
		const types = [{ id: 1, title: 'Besluit' }]
		objectStore.fetchCollection.mockResolvedValue({ data: { results: types } })
		const ctx = vm()

		ctx.fetchPublicationType()
		expect(ctx.publicationTypeLoading).toBe(true)
		await flush()

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication_type')
		expect(ctx.publicationTypeList).toBe(types)
		expect(ctx.publicationTypeLoading).toBe(false)
	})

	it('stops loading when the fetch fails', async () => {
		objectStore.fetchCollection.mockRejectedValue(new Error('offline'))
		const ctx = vm({ publicationTypeList: ['kept'] })

		ctx.fetchPublicationType()
		await flush()

		expect(ctx.publicationTypeLoading).toBe(false)
		expect(ctx.publicationTypeList).toEqual(['kept'])
	})
})

describe('DashboardSideBar addPublication', () => {
	/**
	 * A context with a filled-in form.
	 *
	 * @return {object} The context.
	 */
	function filled() {
		const ctx = vm({ addAttachment: jest.fn() })
		ctx.publicationItem = { ...ctx.publicationItem, title: 'Besluit', summary: 'Kort' }
		ctx.catalogi = { options: [], value: { id: 'c1' } }
		ctx.publicationType = { value: { id: 't1' } }
		return ctx
	}

	it('creates the publication in the chosen catalog and type, then refreshes and clears', async () => {
		jest.useFakeTimers()
		objectStore.createObject.mockResolvedValue({ ok: true, json: async () => ({ id: 42, title: 'Besluit' }) })
		const ctx = filled()
		ctx.success = true

		ctx.addPublication()
		expect(ctx.loading).toBe(true)
		expect(ctx.error).toBe(false)
		await flush()

		expect(objectStore.createObject).toHaveBeenCalledWith('publication', {
			title: 'Besluit',
			summary: 'Kort',
			reference: '',
			category: '',
			portal: '',
			license: '',
			catalog: 'c1',
			publicationType: 't1',
		})
		expect(ctx.loading).toBe(false)
		expect(ctx.success).toBe(true)
		expect(ctx.addAttachment).not.toHaveBeenCalled()
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication')

		jest.advanceTimersByTime(2000)
		expect(ctx.publicationItem.title).toBe('')
		expect(ctx.error).toBeNull()
	})

	it('uploads the selected file onto the new publication', async () => {
		mockFiles.value = [{ name: 'besluit.pdf' }]
		objectStore.createObject.mockResolvedValue({ ok: true, json: async () => ({ id: 42, title: 'Besluit' }) })
		const ctx = filled()

		ctx.addPublication()
		await flush()

		expect(ctx.addAttachment).toHaveBeenCalledTimes(1)
		expect(ctx.addAttachment.mock.calls[0][0]).toMatchObject({ id: '42', title: 'Besluit' })
	})

	it('uploads nothing when the publication was refused', async () => {
		mockFiles.value = [{ name: 'besluit.pdf' }]
		objectStore.createObject.mockResolvedValue({ ok: false, json: async () => ({}) })
		const ctx = filled()

		ctx.addPublication()
		await flush()

		expect(ctx.success).toBe(false)
		expect(ctx.addAttachment).not.toHaveBeenCalled()
	})

	it('keeps the error and the form when the create fails', async () => {
		objectStore.createObject.mockRejectedValue('refused')
		const ctx = filled()

		ctx.addPublication()
		await flush()

		expect(ctx.error).toBe('refused')
		expect(ctx.loading).toBe(false)
		expect(ctx.publicationItem.title).toBe('Besluit')
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
	})
})

describe('DashboardSideBar handleViewPublication', () => {
	/**
	 * A context with a router spy and an optional route slug.
	 *
	 * @param {string|undefined} routeSlug The current route's catalog slug.
	 * @return {object} The context.
	 */
	function routed(routeSlug) {
		return vm({ $router: { push: jest.fn() }, $route: { params: { catalogSlug: routeSlug } } })
	}

	beforeEach(() => {
		objectStore.getCollection.mockReturnValue({ results: [{ id: 'c1', slug: 'woo' }] })
	})

	it('opens the publication under the catalog it is listed in', () => {
		const ctx = routed('other')
		const publication = { id: 'p1', catalog: 'c1' }

		ctx.handleViewPublication(publication)

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', publication)
		expect(ctx.$router.push).toHaveBeenCalledWith('/publications/woo/p1')
	})

	it('falls back to the current catalog when it is listed in none', () => {
		const ctx = routed('raad')

		ctx.handleViewPublication({ id: 'p2', catalog: 'unknown' })

		expect(ctx.$router.push).toHaveBeenCalledWith('/publications/raad/p2')
	})

	it('stays put without a catalog or without an id', () => {
		objectStore.getCollection.mockReturnValue(undefined)
		const ctx = routed(undefined)

		ctx.handleViewPublication({ id: 'p3', catalog: 'c1' })
		const withoutId = routed('woo')
		withoutId.handleViewPublication({ catalog: 'c1' })

		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(withoutId.$router.push).not.toHaveBeenCalled()
		expect(objectStore.setActiveObject).toHaveBeenCalledTimes(2)
	})
})
