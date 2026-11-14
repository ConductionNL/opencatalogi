/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `EditListingModal.vue`: saving updates the active
 * listing, refreshes the collection and closes; a failed save logs, stays
 * open and re-enables the buttons; cancel only closes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import EditListingModal from './EditListingModal.vue'

jest.mock('@nextcloud/vue', () => {
	const actual = jest.requireActual('../../../tests/mocks/nextcloud-vue.js')
	const { h } = jest.requireActual('vue')
	return {
		...actual,
		NcInputField: {
			name: 'NcInputField',
			props: { modelValue: { type: String, default: '' }, label: { type: String, default: '' }, disabled: Boolean },
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					class: 'nc-input-field-stub',
					'aria-label': this.label,
					value: this.modelValue,
					disabled: this.disabled,
					onInput: (e) => this.$emit('update:modelValue', e.target.value),
				})
			},
		},
	}
})

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {
		getActiveObject: jest.fn(),
		updateObject: jest.fn(),
		fetchCollection: jest.fn(),
	},
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the modal over the given active listing.
 *
 * @param {object} listing - The active listing
 * @return {object} The wrapper
 */
function mountModal(listing) {
	objectStore.getActiveObject.mockReturnValue(listing)
	return mount(EditListingModal)
}

describe('EditListingModal', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('shows the active listing in its fields', () => {
		const wrapper = mountModal({ id: 'l1', title: 'Peer', description: 'A peer' })
		const inputs = wrapper.findAll('.nc-input-field-stub')

		expect(objectStore.getActiveObject).toHaveBeenCalledWith('listing')
		expect(inputs.map((i) => i.element.value)).toEqual(['Peer', 'A peer'])
		expect(inputs.map((i) => i.attributes('aria-label'))).toEqual(['Title', 'Description'])
	})

	it('writes edits into the active listing', async () => {
		const listing = { id: 'l1', title: 'Peer', description: '' }
		const wrapper = mountModal(listing)
		await wrapper.findAll('.nc-input-field-stub')[0].setValue('Renamed')

		expect(listing.title).toBe('Renamed')
	})

	it('saves the listing, refreshes the collection and closes', async () => {
		const listing = { id: 'l1', title: 'Peer', description: '' }
		objectStore.updateObject.mockResolvedValue({})
		objectStore.fetchCollection.mockResolvedValue([])
		const wrapper = mountModal(listing)

		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(objectStore.updateObject).toHaveBeenCalledWith('listing', 'l1', listing)
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('listing')
		expect(objectStore.updateObject.mock.invocationCallOrder[0])
			.toBeLessThan(objectStore.fetchCollection.mock.invocationCallOrder[0])
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.findAll('button').every((b) => b.element.disabled === false)).toBe(true)
	})

	it('disables the fields and buttons while saving', async () => {
		let release
		objectStore.updateObject.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mountModal({ id: 'l1', title: '', description: '' })

		await wrapper.findAll('button')[1].trigger('click')

		expect(wrapper.findAll('button').every((b) => b.element.disabled)).toBe(true)
		expect(wrapper.findAll('.nc-input-field-stub').every((i) => i.element.disabled)).toBe(true)
		release()
		await flushPromises()
		expect(wrapper.findAll('button').every((b) => b.element.disabled)).toBe(false)
	})

	it('logs a failed save and stays open', async () => {
		const failure = new Error('refused')
		objectStore.updateObject.mockRejectedValue(failure)
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mountModal({ id: 'l1', title: '', description: '' })

		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(console.error).toHaveBeenCalledWith('Error saving listing:', failure)
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(wrapper.findAll('button')[1].element.disabled).toBe(false)
		console.error.mockRestore()
	})

	it('closes without saving on cancel', async () => {
		const wrapper = mountModal({ id: 'l1', title: '', description: '' })

		await wrapper.findAll('button')[0].trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(objectStore.updateObject).not.toHaveBeenCalled()
	})
})
