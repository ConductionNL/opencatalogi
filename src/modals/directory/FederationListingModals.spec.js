/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the federation listing modals' lifecycle: when each
 * renders, what opening it resets, what it shows for each state, and how the
 * delete modal's delayed close is cancelled. The requests themselves are
 * covered by FederationListingActions.spec.js.
 */

import { flushPromises, mount } from '@vue/test-utils'
import FederationAddDirectoryModal from './FederationAddDirectoryModal.vue'
import FederationDeleteListingModal from './FederationDeleteListingModal.vue'
import FederationEditListingModal from './FederationEditListingModal.vue'

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))

jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return { navigationStore: reactive({ modal: null, setModal: jest.fn() }) }
})

const { navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Open the given modal and let the watchers run.
 *
 * @param {string} name - The modal name
 * @return {Promise<void>}
 */
async function open(name) {
	navigationStore.modal = name
	await flushPromises()
}

/**
 * The visible text of the buttons.
 *
 * @param {object} wrapper - The wrapper
 * @return {string[]} The texts
 */
function buttons(wrapper) {
	return wrapper.findAll('button').map((b) => b.text())
}

beforeEach(() => {
	navigationStore.modal = null
	navigationStore.setModal.mockClear()
})

afterEach(() => {
	jest.useRealTimers()
	delete global.fetch
})

describe('FederationAddDirectoryModal', () => {
	it('renders only while it is the open modal', async () => {
		const wrapper = mount(FederationAddDirectoryModal)
		expect(wrapper.find('.federation-add-directory-modal').exists()).toBe(false)

		await open('federationAddDirectory')
		expect(wrapper.find('h2').text()).toBe('Add directory')
		expect(buttons(wrapper)).toEqual(['Cancel', 'Add'])
		expect(wrapper.findAll('button')[1].element.disabled).toBe(true)
	})

	it('resets a previous submission when it opens again', async () => {
		const wrapper = mount(FederationAddDirectoryModal)
		await wrapper.setData({ url: 'https://old', submitting: true, error: 'old', result: { listings_created: 1 } })

		await open('federationAddDirectory')

		expect(wrapper.vm.url).toBe('')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.result).toBe(null)
	})

	it('enables Add for a URL and shows Adding… while submitting', async () => {
		await open('federationAddDirectory')
		const wrapper = mount(FederationAddDirectoryModal)
		await wrapper.setData({ url: ' https://peer ' })
		expect(wrapper.findAll('button')[1].element.disabled).toBe(false)

		await wrapper.setData({ submitting: true })
		expect(buttons(wrapper)[1]).toBe('Adding…')
		expect(wrapper.findAll('button')[1].element.disabled).toBe(true)
	})

	it('shows the error of a refused add', async () => {
		await open('federationAddDirectory')
		const wrapper = mount(FederationAddDirectoryModal)
		await wrapper.setData({ error: 'HTTP 500' })

		const note = wrapper.find('.nc-note-card-stub--error')
		expect(note.text()).toContain('Failed to add directory')
		expect(note.text()).toContain('HTTP 500')
	})

	it('shows the result instead of the form and offers Close', async () => {
		await open('federationAddDirectory')
		const wrapper = mount(FederationAddDirectoryModal)
		await wrapper.setData({ result: { listings_created: 4, listings_updated: 5, listings_failed: 6 } })

		const note = wrapper.find('.nc-note-card-stub--success').text()
		expect(note).toContain('Directory added')
		expect(note).toMatch(/New listings:\s*4/)
		expect(note).toMatch(/Updated listings:\s*5/)
		expect(note).toMatch(/Failed listings:\s*6/)
		expect(wrapper.find('.NcTextField-stub').exists()).toBe(false)
		expect(buttons(wrapper)).toEqual(['Close'])
	})

	it('closes through the navigation store', async () => {
		await open('federationAddDirectory')
		const wrapper = mount(FederationAddDirectoryModal)

		await wrapper.findAll('button')[0].trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
	})
})

describe('FederationEditListingModal', () => {
	it('renders only while open and with a listing', async () => {
		const wrapper = mount(FederationEditListingModal, { props: { listing: null } })
		await open('federationEditListing')
		expect(wrapper.find('.federation-edit-listing-modal').exists()).toBe(false)

		await wrapper.setProps({ listing: { id: 'l1', title: 'Peer', directory: 'https://peer' } })
		expect(wrapper.find('h2').text()).toBe('Edit listing')
		expect(wrapper.find('.federation-edit-listing-modal__hint').text()).toBe('Peer')
		const input = wrapper.find('input')
		expect(input.element.value).toBe('https://peer')
		expect(input.attributes('id')).toBe('federationEditListingUrl-l1')
		expect(input.attributes('readonly')).toBeDefined()
		expect(wrapper.findAll('.nc-select-stub li').map((li) => li.text()))
			.toEqual(['Federated search', 'Full sync', 'Disabled'])
	})

	it('falls back to a fixed id suffix for a listing without an id', async () => {
		await open('federationEditListing')
		const wrapper = mount(FederationEditListingModal, { props: { listing: { title: 'Peer' } } })

		expect(wrapper.find('input').attributes('id')).toBe('federationEditListingUrl-x')
	})

	it.each([
		['sync', 'sync'],
		['none', 'none'],
		[undefined, 'search'],
		['unknown', 'search'],
	])('selects the level of a listing whose level is %s', async (level, expected) => {
		const wrapper = mount(FederationEditListingModal, { props: { listing: null } })

		await wrapper.setProps({ listing: { id: 'l1', integrationLevel: level } })

		expect(wrapper.vm.integrationLevel.value).toBe(expected)
	})

	it('resets the error and the level when it opens', async () => {
		const wrapper = mount(FederationEditListingModal, { props: { listing: { id: 'l1', integrationLevel: 'none' } } })
		await wrapper.setData({ error: 'old', submitting: true, integrationLevel: null })

		await open('federationEditListing')

		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.integrationLevel.value).toBe('none')
	})

	it('shows a failed save and Saving… while submitting', async () => {
		await open('federationEditListing')
		const wrapper = mount(FederationEditListingModal, { props: { listing: { id: 'l1' } } })
		await wrapper.setData({ error: 'HTTP 409', submitting: true })

		const note = wrapper.find('.nc-note-card-stub--error').text()
		expect(note).toContain('Failed to save listing')
		expect(note).toContain('HTTP 409')
		expect(buttons(wrapper)).toEqual(['Cancel', 'Saving…'])
		expect(wrapper.findAll('button')[1].element.disabled).toBe(true)
	})

	it('closes through the navigation store on cancel', async () => {
		await open('federationEditListing')
		const wrapper = mount(FederationEditListingModal, { props: { listing: { id: 'l1' } } })

		await wrapper.findAll('button')[0].trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
	})
})

describe('FederationDeleteListingModal', () => {
	it.each([
		[{ id: 'l1', title: 'Title', directory: 'https://dir' }, 'Title'],
		[{ id: 'l1', directory: 'https://dir' }, 'https://dir'],
		[{ id: 'l1' }, 'Unnamed instance'],
	])('names the listing %j as %s in the question', async (listing, name) => {
		await open('federationDeleteListing')
		const wrapper = mount(FederationDeleteListingModal, { props: { listing } })

		expect(wrapper.vm.listingName).toBe(name)
		expect(wrapper.find('p').text()).toContain(`Remove ${name} from the directory?`)
	})

	it('renders only while open and with a listing', async () => {
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })
		expect(wrapper.find('.federation-delete-listing-modal').exists()).toBe(false)

		await open('federationDeleteListing')
		expect(wrapper.find('.federation-delete-listing-modal').exists()).toBe(true)

		await wrapper.setProps({ listing: null })
		expect(wrapper.find('.federation-delete-listing-modal').exists()).toBe(false)
	})

	it('shows the loading state instead of the question', async () => {
		await open('federationDeleteListing')
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })
		await wrapper.setData({ loading: true })

		expect(wrapper.find('.federation-delete-listing-modal__loading').text()).toContain('Removing…')
		expect(wrapper.text()).not.toContain('from the directory?')
	})

	it('shows success and failure notes', async () => {
		await open('federationDeleteListing')
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })

		await wrapper.setData({ success: true })
		expect(wrapper.find('.nc-note-card-stub--success').text()).toBe('Removed from directory')
		expect(wrapper.text()).not.toContain('from the directory?')

		await wrapper.setData({ success: false, error: 'HTTP 403' })
		expect(wrapper.findAll('.nc-note-card-stub--error').map((n) => n.text()))
			.toEqual(['Removal failed', 'HTTP 403'])
	})

	it('resets its state and drops a pending close when it opens', async () => {
		jest.useFakeTimers()
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })
		wrapper.vm.scheduleClose()
		await wrapper.setData({ loading: true, success: true, error: 'old' })

		navigationStore.modal = 'federationDeleteListing'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.closeTimer).toBe(null)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.success).toBe(null)
		expect(wrapper.vm.error).toBe(null)
		jest.advanceTimersByTime(1000)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('closes at once and drops a pending close', async () => {
		jest.useFakeTimers()
		await open('federationDeleteListing')
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })
		wrapper.vm.scheduleClose()

		await wrapper.findAll('button')[0].trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(wrapper.vm.closeTimer).toBe(null)
		jest.advanceTimersByTime(1000)
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
	})

	it('cancels a pending close when it unmounts', async () => {
		jest.useFakeTimers()
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l1' } } })
		wrapper.vm.scheduleClose()

		wrapper.unmount()
		jest.advanceTimersByTime(1000)

		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('deletes from the remove button', async () => {
		await open('federationDeleteListing')
		global.fetch = jest.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'no' }) }))
		const wrapper = mount(FederationDeleteListingModal, { props: { listing: { id: 'l9' } } })

		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(global.fetch.mock.calls[0][0]).toBe('/nc/apps/opencatalogi/api/listings/l9')
		expect(wrapper.vm.error).toBe('no')
	})
})
