/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `ViewDirectoryModal.vue`: which listing fields it shows
 * and their fallbacks, the empty state, and that closing clears the active
 * listing.
 */

import { mount } from '@vue/test-utils'
import ViewDirectoryModal from './ViewDirectoryModal.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'viewDirectory', setModal: jest.fn() },
	objectStore: { getActiveObject: jest.fn(), clearActiveObject: jest.fn() },
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the modal over the given active listing.
 *
 * @param {object|null} listing - The active listing
 * @return {object} The wrapper
 */
function mountModal(listing) {
	objectStore.getActiveObject.mockReturnValue(listing)
	return mount(ViewDirectoryModal)
}

/**
 * The text of each detail item.
 *
 * @param {object} wrapper - The wrapper
 * @return {string[]} The texts
 */
function details(wrapper) {
	return wrapper.findAll('.detailItem').map((d) => `${d.find('strong').text()} ${d.find('span').text()}`)
}

describe('ViewDirectoryModal', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		navigationStore.modal = 'viewDirectory'
	})

	it('shows every section of a full listing', () => {
		const wrapper = mountModal({
			name: 'Peer',
			title: 'Ignored',
			summary: 'Short',
			description: 'Long',
			organization: { title: 'Org' },
			publicationTypes: [
				{ id: 'p1', title: 'Besluit', description: 'Decisions' },
				{ id: 'p2', name: 'Rapport' },
				'Plain',
			],
			metadata: { a: 1 },
		})

		expect(objectStore.getActiveObject).toHaveBeenCalledWith('listing')
		expect(wrapper.find('h2').text()).toBe('Peer')
		expect(details(wrapper)).toEqual([
			'Name: Peer',
			'Summary: Short',
			'Description: Long',
			'Organization: Org',
		])
		const types = wrapper.findAll('.publicationTypeItem')
		expect(types.map((p) => p.find('strong').text())).toEqual(['Besluit', 'Rapport', 'Plain'])
		expect(types[0].find('.description').text()).toBe('Decisions')
		expect(types[1].find('.description').exists()).toBe(false)
		expect(wrapper.find('pre').text()).toBe(JSON.stringify({ a: 1 }, null, 2))
		expect(wrapper.find('.emptyState').exists()).toBe(false)
	})

	it('falls back to the title and shows a plain organization', () => {
		const wrapper = mountModal({ title: 'Titled', organization: 'Plain org' })

		expect(wrapper.find('h2').text()).toBe('Titled')
		expect(details(wrapper)).toEqual(['Name: Titled', 'Organization: Plain org'])
	})

	it('leaves out the optional sections of a bare listing', () => {
		const wrapper = mountModal({ publicationTypes: [] })

		expect(wrapper.find('h2').text()).toBe('Directory Listing')
		expect(details(wrapper)).toEqual(['Name: -'])
		expect(wrapper.find('.publicationTypesList').exists()).toBe(false)
		expect(wrapper.find('pre').exists()).toBe(false)
	})

	it('shows the empty state without a listing', () => {
		const wrapper = mountModal(null)

		expect(wrapper.find('h2').text()).toBe('Directory Listing')
		expect(wrapper.find('.directoryDetails').exists()).toBe(false)
		expect(wrapper.find('.emptyState').text()).toBe('No directory listing selected')
	})

	it('renders nothing while another modal is open', () => {
		navigationStore.modal = 'other'

		expect(mountModal({ name: 'Peer' }).find('.modal__content').exists()).toBe(false)
	})

	it('closes and clears the active listing', async () => {
		const wrapper = mountModal({ name: 'Peer' })

		await wrapper.find('.modalActions button').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(objectStore.clearActiveObject).toHaveBeenCalledWith('listing')
	})
})
