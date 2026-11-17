/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the header and row clicks of `PublicationList.vue`:
 * the search field, the status filter checkboxes, the sort radios, the
 * help / refresh / add actions, opening a row and publishing a row through
 * the real request, rendered against a mocked store.
 */

import { flushPromises, mount } from '@vue/test-utils'
import PublicationList from './PublicationList.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))

jest.mock('@nextcloud/vue', () => {
	const slots = '<div><slot name="icon" /><slot name="subname" /><slot name="actions" /><slot /></div>'
	const plain = { template: '<div />' }
	return {
		NcActionButton: {
			emits: ['click'],
			template: '<button class="action" @click="$emit(\'click\')"><slot /></button>',
		},
		NcActionCaption: plain,
		NcActionCheckbox: {
			props: ['value'],
			emits: ['change'],
			template: '<input type="checkbox" class="filter" :value="value" @change="$emit(\'change\', $event)" />',
		},
		NcActionInput: plain,
		NcActionRadio: {
			props: ['value'],
			emits: ['update:modelValue'],
			template: '<input type="radio" class="sort" :value="value" @change="$emit(\'update:modelValue\', value)" />',
		},
		NcActions: { template: '<div class="menu"><slot /></div>' },
		NcActionSeparator: plain,
		NcAppContentList: { template: slots },
		NcListItem: { emits: ['click'], template: `<div class="row" @click="$emit('click')">${slots}</div>` },
		NcLoadingIcon: plain,
		NcTextField: {
			emits: ['update:modelValue', 'trailingButtonClick'],
			template: '<div class="search"><input @input="$emit(\'update:modelValue\', $event.target.value)" /><button class="clear" @click="$emit(\'trailingButtonClick\')" /></div>',
		},
	}
})

jest.mock('../../store/store.js', () => {
	const active = {}
	const publications = [
		{ id: 'p1', title: 'Draft', '@self': { register: 'r1', schema: 's1', published: null } },
		{ id: 'p2', title: 'Live', '@self': { register: 'r1', schema: 's1', published: '2026-01-01' } },
	]
	return {
		__active: active,
		catalogStore: { isLoading: false, fetchPublications: jest.fn() },
		navigationStore: {
			setModal: jest.fn(),
			setDialog: jest.fn(),
			setTransferData: jest.fn(),
		},
		objectStore: {
			getActiveObject: (type) => active[type] ?? null,
			setActiveObject: jest.fn((type, object) => {
				active[type] = object
			}),
			clearActiveObject: jest.fn(),
			getCollection: () => ({ results: publications }),
			isLoading: () => false,
			getSearchTerm: () => '',
			setSearchTerm: jest.fn(),
			clearSearchTerm: jest.fn(),
		},
	}
})

const { __active: active } = jest.requireMock('../../store/store.js')

/**
 * Mount the list with a router that records pushes.
 *
 * @return {{wrapper: object, push: Function}} The wrapper and the push mock.
 */
function mountList() {
	const push = jest.fn()
	const wrapper = mount(PublicationList, {
		global: {
			mocks: { t: (app, text) => text },
			config: { globalProperties: { $route: { params: { catalogSlug: 'woo' } }, $router: { push } } },
		},
	})
	return { wrapper, push }
}

/**
 * The menu or row button with exactly `text`.
 *
 * @param {object} scope Where to look.
 * @param {string} text The label.
 * @return {object} The button.
 */
function action(scope, text) {
	return scope.findAll('button.action').find((b) => b.text() === text)
}

beforeEach(() => {
	jest.clearAllMocks()
	for (const key of Object.keys(active)) delete active[key]
	window.open = jest.fn()
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ '@self': { id: 'p1' }, title: 'Draft' }) }))
})

afterEach(() => {
	delete global.fetch
})

describe('PublicationList header', () => {
	it('searches as you type and clears the search from its button', async () => {
		const { wrapper } = mountList()

		await wrapper.find('.search input').setValue('rapport')
		await wrapper.find('.search .clear').trigger('click')

		expect(objectStore.setSearchTerm).toHaveBeenCalledWith('publication', 'rapport')
		expect(objectStore.clearSearchTerm).toHaveBeenCalledWith('publication')
	})

	it('toggles the concept and published filters from their checkboxes', async () => {
		const { wrapper } = mountList()
		const [concept, published] = wrapper.findAll('input.filter')

		await concept.setValue(true)
		expect(wrapper.vm.conceptChecked).toBe(true)
		await published.setValue(true)
		expect(wrapper.vm.gepubliceerdChecked).toBe(true)
		await concept.setValue(false)
		expect(wrapper.vm.conceptChecked).toBe(false)
	})

	it('sets the sort direction from the radios', async () => {
		const { wrapper } = mountList()
		const [asc, desc] = wrapper.findAll('input.sort')

		await desc.trigger('change')
		expect(wrapper.vm.sortDirection).toBe('desc')
		await asc.trigger('change')
		expect(wrapper.vm.sortDirection).toBe('asc')
	})

	it('opens the help page, refreshes and starts a new publication', async () => {
		const { wrapper } = mountList()
		const menu = wrapper.find('.listHeader .menu')

		await action(menu, 'Help').trigger('click')
		await action(menu, 'Refresh').trigger('click')
		await action(menu, 'Add publication').trigger('click')

		expect(window.open).toHaveBeenCalledWith('https://opencatalogi.conduction.nl/docs/Users/publicaties/', '_blank')
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		expect(objectStore.clearActiveObject).toHaveBeenCalledWith('publication')
		expect(navigationStore.setModal).toHaveBeenCalledWith('objectModal')
	})
})

describe('PublicationList rows', () => {
	it('opens a row on its detail route and makes it active', async () => {
		const { wrapper, push } = mountList()

		await wrapper.findAll('.row')[1].trigger('click')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', expect.objectContaining({ id: 'p2' }))
		expect(push).toHaveBeenCalledWith('/publications/woo/p2')
	})

	it('publishes a draft row through the publish endpoint', async () => {
		const { wrapper } = mountList()

		await action(wrapper.findAll('.row')[0], 'Publish').trigger('click')
		await flushPromises()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r1/s1/p1/publish', {
			method: 'POST',
			headers: { requesttoken: 'token-1' },
		})
		expect(catalogStore.fetchPublications).toHaveBeenCalled()
		expect(active.publication).toEqual({ '@self': { id: 'p1' }, title: 'Draft', id: 'p1' })
	})

	it('depublishes a published row through the depublish endpoint', async () => {
		const { wrapper } = mountList()
		const row = wrapper.findAll('.row')[1]

		expect(action(row, 'Publish')).toBeUndefined()
		await action(row, 'Depublish').trigger('click')

		expect(global.fetch.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/r1/s1/p2/depublish')
	})
})
