/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the states of `DashboardSideBar.vue`'s template: the
 * search field and its error, the quick publication's Add button (disabled
 * until the form is complete, a spinner while adding), the success and error
 * notes after an add, the View action, and the notes shown when no
 * publication or attachment needs attention.
 */

import { mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import DashboardSideBar from './DashboardSideBar.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/vue', () => {
	const slots = '<div><slot name="icon" /><slot name="subname" /><slot name="actions" /><slot /></div>'
	return {
		NcAppSidebar: { template: slots },
		NcAppSidebarTab: { props: { id: { type: String, default: '' } }, template: '<section :id="id"><slot name="icon" /><slot /></section>' },
		NcListItem: { template: slots },
		NcActionButton: {
			emits: ['click'],
			template: '<button class="row-action" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
		},
		NcNoteCard: { props: { type: { type: String, default: '' } }, template: '<div class="note" :data-type="type"><slot /></div>' },
		NcButton: {
			props: { disabled: Boolean },
			emits: ['click'],
			template: '<button class="add" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
		},
		NcSelect: { template: '<div class="select" />' },
		NcLoadingIcon: { template: '<span class="spinner" />' },
		NcTextField: {
			props: { modelValue: { type: String, default: '' }, label: { type: String, default: '' } },
			emits: ['update:modelValue'],
			template: '<input class="text" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
		},
	}
})

jest.mock('../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({ files: { value: [] }, reset: () => {} }),
}))

const mockState = reactive({
	searchError: null,
	collections: {},
})

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	objectStore: {
		getActiveObject: () => null,
		setActiveObject: jest.fn(),
		getCollection: (type) => mockState.collections[type] ?? { results: [] },
		fetchCollection: jest.fn(),
		getSearchTerm: () => 'water',
		setSearchTerm: jest.fn(),
		getError: () => mockState.searchError,
	},
}))


/**
 * Mount the sidebar with its data fetches switched off.
 *
 * @return {object} The wrapper.
 */
function mountSideBar() {
	jest.spyOn(DashboardSideBar.methods, 'fetchCatalogi').mockImplementation(() => {})
	jest.spyOn(DashboardSideBar.methods, 'fetchPublicationType').mockImplementation(() => {})
	return mount(DashboardSideBar, {
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * The notes of one tab, as type: text.
 *
 * @param {object} wrapper The mounted sidebar.
 * @param {string} tab     The tab id.
 * @return {string[]}
 */
function notes(wrapper, tab) {
	return wrapper.findAll(`#${tab} .note`).map((note) => `${note.attributes('data-type')}: ${note.text()}`)
}

describe('DashboardSideBar states', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockState.searchError = null
		mockState.collections = {}
	})

	it('binds the search term, writes it back, and shows a search error', async () => {
		const wrapper = mountSideBar()
		const field = wrapper.find('#search-tab input.text')

		expect(field.element.value).toBe('water')
		await field.setValue('wind')
		expect(objectStore.setSearchTerm).toHaveBeenCalledWith('search', 'wind')
		expect(notes(wrapper, 'search-tab')).toEqual([])

		mockState.searchError = 'The directory did not answer'
		await nextTick()
		expect(notes(wrapper, 'search-tab')).toEqual(['error: The directory did not answer'])
	})

	it('keeps Add disabled until catalog, type, title and summary are set, then adds', async () => {
		const add = jest.spyOn(DashboardSideBar.methods, 'addPublication').mockImplementation(() => {})
		const wrapper = mountSideBar()
		const button = () => wrapper.find('#publication-creation-tab button.add')

		expect(button().attributes('disabled')).toBeDefined()
		wrapper.vm.$data.catalogi.value = { id: 'c1' }
		wrapper.vm.$data.publicationType.value = { id: 't1' }
		wrapper.vm.$data.publicationItem.title = 'Besluit'
		await nextTick()
		expect(button().attributes('disabled')).toBeDefined()

		wrapper.vm.$data.publicationItem.summary = 'Over water'
		await nextTick()
		expect(button().attributes('disabled')).toBeUndefined()
		expect(button().find('.spinner').exists()).toBe(false)
		await button().trigger('click')
		expect(add).toHaveBeenCalledTimes(1)
	})

	it('shows a spinner instead of the plus while adding', async () => {
		const wrapper = mountSideBar()

		wrapper.vm.$data.loading = true
		await nextTick()

		const button = wrapper.find('#publication-creation-tab button.add')
		expect(button.find('.spinner').exists()).toBe(true)
				expect(button.attributes('disabled')).toBeDefined()
	})

	it('says a publication was added, or that adding failed and why', async () => {
		const wrapper = mountSideBar()
		expect(notes(wrapper, 'publication-creation-tab')).toEqual([])

		wrapper.vm.$data.success = true
		await nextTick()
		expect(notes(wrapper, 'publication-creation-tab')).toEqual(['success: Publication successfully added'])

		wrapper.vm.$data.success = false
		wrapper.vm.$data.error = 'Title is taken'
		await nextTick()
		expect(notes(wrapper, 'publication-creation-tab')).toEqual([
			'error: Something went wrong while adding the publication',
			'error: Title is taken',
		])
	})

	it('opens a publication from its View action', async () => {
		mockState.collections = { publication: { results: [{ id: 'p1', title: 'One', description: 'First' }] } }
		const view = jest.spyOn(DashboardSideBar.methods, 'handleViewPublication').mockImplementation(() => {})
		const wrapper = mountSideBar()

		const viewButton = wrapper.findAll('#settings-tab button.row-action').find((b) => b.text() === 'View')
		await viewButton.trigger('click')

		expect(view).toHaveBeenCalledWith({ id: 'p1', title: 'One', description: 'First' })
		expect(wrapper.find('#settings-tab').text()).toContain('First')
		expect(notes(wrapper, 'settings-tab')).toEqual([])
	})

	it('says when no publication or attachment needs attention', () => {
		const wrapper = mountSideBar()

		expect(notes(wrapper, 'settings-tab')).toEqual([
			'success: There are no publications that require your attention at the moment',
		])
		expect(notes(wrapper, 'share-tab')).toEqual([
			'success: There are no attachments that require your attention at the moment',
		])
	})
})
