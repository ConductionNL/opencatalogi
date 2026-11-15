/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the methods of `PublicationList.vue`: publishing the active
 * publication, opening a row, the status filter checkboxes and the sort
 * order, run against a mocked store and a mocked `fetch`.
 */

import PublicationList from './PublicationList.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
jest.mock('../../store/store.js', () => {
	const active = {}
	return {
		__active: active,
		catalogStore: { fetchPublications: jest.fn() },
		navigationStore: { setModal: jest.fn(), setTransferData: jest.fn() },
		objectStore: {
			getActiveObject: jest.fn((type) => active[type] ?? null),
			setActiveObject: jest.fn((type, object) => {
				active[type] = object
			}),
			getCollection: jest.fn(() => ({ results: [{ id: 'p1' }] })),
		},
	}
})

const { __active: active } = jest.requireMock('../../store/store.js')
const PUBLICATION = { id: 'p1', '@self': { register: 'r1', schema: 's1' } }

/**
 * Wait for queued promise callbacks to run.
 *
 * @return {Promise<void>}
 */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * A component-like state with data() and the bound methods.
 *
 * @return {object} The state.
 */
function makeVm() {
	const vm = { ...PublicationList.data() }
	for (const [name, fn] of Object.entries(PublicationList.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	for (const key of Object.keys(active)) delete active[key]
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ '@self': { id: 'p1' }, title: 'New' }) }))
})

afterEach(() => {
	delete global.fetch
})

describe('PublicationList', () => {
	it('lists the publication collection', () => {
		expect(PublicationList.computed.publicationsResults()).toEqual([{ id: 'p1' }])
		expect(objectStore.getCollection).toHaveBeenCalledWith('publication')
	})

	it('publishes the active publication and stores the response as active', async () => {
		active.publication = PUBLICATION

		makeVm().publishPublication('depublish')
		await flush()

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r1/s1/p1/depublish',
			{ method: 'POST', headers: { requesttoken: 'token-1' } },
		)
		expect(catalogStore.fetchPublications).toHaveBeenCalled()
		expect(active.publication).toEqual({ '@self': { id: 'p1' }, title: 'New', id: 'p1' })
	})

	it('keeps an id the response already carries', async () => {
		active.publication = PUBLICATION
		global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ id: 'own', '@self': { id: 'self' } }) }))

		makeVm().publishPublication('publish')
		await flush()

		expect(active.publication.id).toBe('own')
	})

	it('activates a row before publishing it', () => {
		const vm = makeVm()
		vm.publishPublication = jest.fn()

		vm.activateAndPublish(PUBLICATION, 'publish')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', PUBLICATION)
		expect(vm.publishPublication).toHaveBeenCalledWith('publish')
	})

	it('opens a row on its files tab', () => {
		makeVm().openFileList(PUBLICATION)

		expect(active.publication).toBe(PUBLICATION)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ initialTab: 'files' })
		expect(navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})

	it('routes to a publication within the current catalog', () => {
		const vm = makeVm()
		vm.$router = { push: jest.fn() }
		vm.$route = { params: { catalogSlug: 'woo' } }

		vm.toggleActive(PUBLICATION)

		expect(active.publication).toBe(PUBLICATION)
		expect(vm.$router.push).toHaveBeenCalledWith('/publications/woo/p1')
	})

	it('tracks the status filter checkboxes and ignores other keys', () => {
		const vm = makeVm()

		vm.handleCheckboxChange('concept', { target: { checked: true } })
		vm.handleCheckboxChange('gepubliceerd', { target: { checked: true } })
		vm.handleCheckboxChange('other', { target: { checked: false } })
		expect([vm.conceptChecked, vm.gepubliceerdChecked]).toEqual([true, true])

		vm.handleCheckboxChange('concept', { target: { checked: false } })
		expect([vm.conceptChecked, vm.gepubliceerdChecked]).toEqual([false, true])
	})

	it('updates the sort direction', () => {
		const vm = makeVm()

		vm.updateSortOrder('asc')

		expect(vm.sortDirection).toBe('asc')
	})

	it('opens a link in the given target', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)

		makeVm().openLink('https://a', '_blank')

		expect(open).toHaveBeenCalledWith('https://a', '_blank')
		open.mockRestore()
	})
})
