/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for `ViewThemeModal.vue`: when it shows, which detail
 * sections render for a theme, and what the edit and close actions do to the
 * navigation and object stores.
 */

import { mount } from '@vue/test-utils'
import ViewThemeModal from './ViewThemeModal.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'viewTheme', setModal: jest.fn() },
	objectStore: {
		getActiveObject: jest.fn(),
		clearActiveObject: jest.fn(),
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
	emits: ['click'],
	template: '<button class="nc-button" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
}

/**
 * Mount the modal with the given active theme.
 *
 * @param {object|null} theme - What the store returns as the active theme
 * @return {object} The wrapper
 */
function mountModal(theme) {
	objectStore.getActiveObject.mockReturnValue(theme)
	return mount(ViewThemeModal, {
		global: {
			stubs: { NcModal, NcButton, Pencil: true, Cancel: true },
		},
	})
}

/**
 * The text of every section heading.
 *
 * @param {object} wrapper - The wrapper
 * @return {string[]} The headings
 */
function headings(wrapper) {
	return wrapper.findAll('h3').map((h) => h.text())
}

/**
 * The "label: value" pairs of the detail items.
 *
 * @param {object} wrapper - The wrapper
 * @return {object} Label to value
 */
function details(wrapper) {
	const out = {}
	for (const item of wrapper.findAll('.detailItem')) {
		out[item.find('strong').text()] = item.find('span').text()
	}
	return out
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper - The wrapper
 * @param {string} text - The label
 * @return {object} The button wrapper
 */
function button(wrapper, text) {
	return wrapper.findAll('button.nc-button').find((b) => b.text() === text)
}

beforeEach(() => {
	navigationStore.modal = 'viewTheme'
	navigationStore.setModal.mockReset()
	objectStore.getActiveObject.mockReset()
	objectStore.clearActiveObject.mockReset()
})

describe('ViewThemeModal', () => {
	it('renders nothing while another modal is open', () => {
		navigationStore.modal = 'theme'
		const wrapper = mountModal({ title: 'Blue' })
		expect(wrapper.find('.nc-modal').exists()).toBe(false)
	})

	it('reads the active theme from the store', () => {
		mountModal({ title: 'Blue' })
		expect(objectStore.getActiveObject).toHaveBeenCalledWith('theme')
	})

	it('shows only the basic section for a theme with just a title', () => {
		const wrapper = mountModal({ title: 'Blue' })
		expect(wrapper.find('.nc-modal').attributes('data-name')).toBe('Blue')
		expect(headings(wrapper)).toEqual(['Basic information'])
		expect(details(wrapper)).toEqual({ 'Title:': 'Blue' })
		expect(wrapper.find('.emptyState').exists()).toBe(false)
	})

	it('falls back to a dash and a generic modal name without a title', () => {
		const wrapper = mountModal({ name: 'blue' })
		expect(wrapper.find('.nc-modal').attributes('data-name')).toBe('Theme')
		expect(details(wrapper)).toEqual({ 'Title:': '-', 'Name:': 'blue' })
	})

	it('lists every basic field that is set', () => {
		const wrapper = mountModal({
			title: 'Blue',
			name: 'blue',
			status: 'active',
			version: '1.2.0',
			summary: 'Short',
			description: 'Long',
		})
		expect(details(wrapper)).toEqual({
			'Title:': 'Blue',
			'Name:': 'blue',
			'Status:': 'active',
			'Version:': '1.2.0',
			'Summary:': 'Short',
			'Description:': 'Long',
		})
	})

	it('prefers the image over the screenshot in the preview', () => {
		const wrapper = mountModal({ title: 'Blue', image: 'a.png', screenshot: 'b.png' })
		const imgs = wrapper.findAll('img.themeImage')
		expect(imgs).toHaveLength(1)
		expect(imgs[0].attributes('src')).toBe('a.png')
		expect(imgs[0].attributes('alt')).toBe('Blue')
		expect(headings(wrapper)).toContain('Visual preview')
	})

	it('shows the screenshot when there is no image', () => {
		const wrapper = mountModal({ screenshot: 'b.png' })
		const imgs = wrapper.findAll('img.themeImage')
		expect(imgs).toHaveLength(1)
		expect(imgs[0].attributes('src')).toBe('b.png')
		expect(imgs[0].attributes('alt')).toBe('Theme screenshot')
	})

	it('uses a generic alt text for an untitled image', () => {
		const wrapper = mountModal({ image: 'a.png' })
		expect(wrapper.find('img.themeImage').attributes('alt')).toBe('Theme preview')
	})

	it('prints config, settings and metadata as indented JSON', () => {
		const wrapper = mountModal({
			title: 'Blue',
			config: { color: '#00f' },
			settings: { dark: true },
			metadata: { owner: 'me' },
		})
		expect(headings(wrapper)).toEqual(['Basic information', 'Configuration', 'Metadata'])
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Config', 'Settings'])
		const pres = wrapper.findAll('pre').map((p) => p.text())
		expect(pres).toEqual([
			JSON.stringify({ color: '#00f' }, null, 2),
			JSON.stringify({ dark: true }, null, 2),
			JSON.stringify({ owner: 'me' }, null, 2),
		])
	})

	it('shows only the settings block when there is no config', () => {
		const wrapper = mountModal({ settings: { dark: true } })
		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Settings'])
	})

	it('links the repository and homepage in a new tab', () => {
		const wrapper = mountModal({
			repository: 'https://git.example/blue',
			homepage: 'https://blue.example',
			author: 'Ann',
			license: 'EUPL-1.2',
		})
		expect(headings(wrapper)).toEqual(['Basic information', 'Theme information'])
		const links = wrapper.findAll('a')
		expect(links.map((a) => a.attributes('href'))).toEqual(['https://git.example/blue', 'https://blue.example'])
		for (const a of links) {
			expect(a.attributes('target')).toBe('_blank')
			expect(a.attributes('rel')).toBe('noopener noreferrer')
		}
		expect(details(wrapper)).toMatchObject({ 'Author:': 'Ann', 'License:': 'EUPL-1.2' })
	})

	it('hides the theme information section when it has only dates', () => {
		const wrapper = mountModal({ title: 'Blue', createdAt: '2024-01-02T00:00:00Z' })
		expect(headings(wrapper)).toEqual(['Basic information'])
		expect(wrapper.text()).not.toContain('Created')
	})

	it('formats the dates in the theme information section', () => {
		const updatedAt = '2024-03-04T12:00:00Z'
		const createdAt = '2024-01-02T12:00:00Z'
		const wrapper = mountModal({ author: 'Ann', updatedAt, createdAt })
		expect(details(wrapper)).toMatchObject({
			'Last updated:': new Date(updatedAt).toLocaleDateString(),
			'Created:': new Date(createdAt).toLocaleDateString(),
		})
	})

	it('says so when no theme is selected', () => {
		const wrapper = mountModal(null)
		expect(wrapper.find('.themeDetails').exists()).toBe(false)
		expect(wrapper.find('.emptyState').text()).toBe('No theme selected')
	})

	it('opens the edit modal from the edit button', async () => {
		const wrapper = mountModal({ title: 'Blue' })
		await button(wrapper, 'Edit theme').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith('theme')
		expect(objectStore.clearActiveObject).not.toHaveBeenCalled()
	})

	it('closes and clears the active theme from the close button', async () => {
		const wrapper = mountModal({ title: 'Blue' })
		await button(wrapper, 'Close').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(objectStore.clearActiveObject).toHaveBeenCalledWith('theme')
	})

	it('closes when the modal itself emits close', async () => {
		const wrapper = mountModal({ title: 'Blue' })
		wrapper.findComponent(NcModal).vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(objectStore.clearActiveObject).toHaveBeenCalledWith('theme')
	})
})
