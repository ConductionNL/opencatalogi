/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for `AddPublicationThemeModal.vue` around saveTheme (which
 * AddPublicationThemeSave.spec.js covers): the theme options offered, the
 * enabled state of the select and save button, the success and error notes,
 * and what closing resets.
 */

import { flushPromises, mount } from '@vue/test-utils'
import AddPublicationThemeModal from './AddPublicationThemeModal.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'addPublicationTheme', setModal: jest.fn() },
	objectStore: {
		getCollection: jest.fn(),
		getActiveObject: jest.fn(),
		updateObject: jest.fn(),
		fetchCollection: jest.fn(),
	},
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

const NcModal = {
	name: 'NcModal',
	props: ['name'],
	emits: ['close'],
	template: '<div class="nc-modal" :data-name="name"><slot /></div>',
}

const NcButton = {
	name: 'NcButton',
	props: ['disabled'],
	emits: ['click'],
	template: '<button class="nc-button" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
}

const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'items', 'inputLabel', 'disabled'],
	emits: ['update:modelValue'],
	template: '<div class="nc-select" />',
}

const NcNoteCard = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="nc-note" :data-type="type"><slot /></div>',
}

/**
 * Mount the modal over the given themes and active publication.
 *
 * @param {object} options - The store contents
 * @param {Array|undefined} options.themes - The theme collection results
 * @param {object|null} options.publication - The active publication
 * @return {object} The wrapper
 */
function mountModal({ themes, publication = null }) {
	objectStore.getCollection.mockReturnValue(themes === undefined ? {} : { results: themes })
	objectStore.getActiveObject.mockReturnValue(publication)
	return mount(AddPublicationThemeModal, {
		global: {
			mocks: { t: (app, text) => text },
			stubs: {
				NcModal,
				NcButton,
				NcSelect,
				NcNoteCard,
				NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
				ContentSaveOutline: { name: 'ContentSaveOutline', template: '<i class="save-icon" />' },
			},
		},
	})
}

/**
 * The select stub.
 *
 * @param {object} wrapper - The wrapper
 * @return {object} The NcSelect wrapper
 */
function select(wrapper) {
	return wrapper.findComponent(NcSelect)
}

/**
 * The save button.
 *
 * @param {object} wrapper - The wrapper
 * @return {object} The button wrapper
 */
function saveButton(wrapper) {
	return wrapper.find('button.nc-button')
}

/**
 * Pick a theme in the select.
 *
 * @param {object} wrapper - The wrapper
 * @param {string} id - The theme id
 * @return {Promise<void>}
 */
async function choose(wrapper, id) {
	select(wrapper).vm.$emit('update:modelValue', id)
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	navigationStore.modal = 'addPublicationTheme'
	navigationStore.setModal.mockReset()
	objectStore.getCollection.mockReset()
	objectStore.getActiveObject.mockReset()
	objectStore.updateObject.mockReset()
	objectStore.fetchCollection.mockReset()
})

describe('AddPublicationThemeModal', () => {
	it('renders nothing while another modal is open', () => {
		navigationStore.modal = 'viewTheme'
		const wrapper = mountModal({ themes: [] })
		expect(wrapper.find('.nc-modal').exists()).toBe(false)
	})

	it('offers the themes not yet on the publication, labelled by title or id', () => {
		const wrapper = mountModal({
			themes: [
				{ id: 't1', title: 'Blue' },
				{ id: 't2', title: 'Red' },
				{ id: 't3' },
			],
			publication: { id: 'p1', theme: ['t2'] },
		})
		expect(objectStore.getCollection).toHaveBeenCalledWith('theme')
		expect(objectStore.getActiveObject).toHaveBeenCalledWith('publication')
		expect(select(wrapper).props('items')).toEqual([
			{ value: 't1', label: 'Blue' },
			{ value: 't3', label: '#t3' },
		])
		expect(select(wrapper).props('inputLabel')).toBe('Theme')
		expect(select(wrapper).props('disabled')).toBe(false)
	})

	it('offers every theme when no publication is active', () => {
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: null })
		expect(select(wrapper).props('items')).toEqual([{ value: 't1', label: 'Blue' }])
	})

	it('offers every theme to a publication without themes', () => {
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: { id: 'p1' } })
		expect(select(wrapper).props('items')).toEqual([{ value: 't1', label: 'Blue' }])
	})

	it('treats a collection without results as no themes and disables the select', () => {
		const wrapper = mountModal({ themes: undefined })
		expect(wrapper.vm.themes).toEqual([])
		expect(select(wrapper).props('items')).toEqual([])
		expect(select(wrapper).props('disabled')).toBe(true)
	})

	it('disables saving until a theme is chosen', async () => {
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }] })
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		expect(saveButton(wrapper).find('.save-icon').exists()).toBe(true)
		await choose(wrapper, 't1')
		expect(wrapper.vm.selectedTheme).toBe('t1')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('shows a spinner and disables the controls while saving', async () => {
		objectStore.updateObject.mockReturnValue(new Promise(() => {}))
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: { id: 'p1' } })
		await choose(wrapper, 't1')
		await saveButton(wrapper).trigger('click')
		expect(wrapper.vm.isSaving).toBe(true)
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		expect(saveButton(wrapper).find('.loading').exists()).toBe(true)
		expect(saveButton(wrapper).find('.save-icon').exists()).toBe(false)
		expect(select(wrapper).props('disabled')).toBe(true)
	})

	it('saves the chosen theme and replaces the form with a success note', async () => {
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: { id: 'p1', title: 'P' } })
		await choose(wrapper, 't1')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(objectStore.updateObject).toHaveBeenCalledWith('publication', 'p1', { id: 'p1', title: 'P', theme: 't1' })
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('theme')
		const notes = wrapper.findAll('.nc-note')
		expect(notes).toHaveLength(1)
		expect(notes[0].attributes('data-type')).toBe('success')
		expect(notes[0].text()).toBe('Theme successfully saved')
		expect(select(wrapper).exists()).toBe(false)
		expect(saveButton(wrapper).exists()).toBe(false)
	})

	it('shows a failed save as an error note without the form', async () => {
		objectStore.updateObject.mockRejectedValue(new Error('denied'))
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: { id: 'p1' } })
		await choose(wrapper, 't1')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		const notes = wrapper.findAll('.nc-note')
		expect(notes).toHaveLength(1)
		expect(notes[0].attributes('data-type')).toBe('error')
		expect(notes[0].text()).toBe('denied')
		// successState is false (not null) after a failure, so the form stays hidden.
		expect(select(wrapper).exists()).toBe(false)
	})

	it('shows a missing publication as an error note next to the form', async () => {
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: null })
		await choose(wrapper, 't1')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		const notes = wrapper.findAll('.nc-note')
		expect(notes).toHaveLength(1)
		expect(notes[0].attributes('data-type')).toBe('error')
		expect(notes[0].text()).toBe('No publication selected')
		expect(select(wrapper).exists()).toBe(true)
		expect(objectStore.updateObject).not.toHaveBeenCalled()
	})

	it('closes the modal and resets its state', async () => {
		objectStore.updateObject.mockRejectedValue(new Error('denied'))
		const wrapper = mountModal({ themes: [{ id: 't1', title: 'Blue' }], publication: { id: 'p1' } })
		await choose(wrapper, 't1')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		wrapper.findComponent(NcModal).vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.selectedTheme).toBe(null)
		expect(wrapper.vm.successState).toBe(null)
		expect(wrapper.vm.errorState).toBe(null)
		expect(wrapper.findAll('.nc-note')).toHaveLength(0)
		expect(select(wrapper).exists()).toBe(true)
	})
})
