/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Delete permanently on the publication page: shown for a draft or a
 * publication in review to a caller who may update it, confirmed in its own
 * dialog, sent to `DELETE /api/publications/{id}/draft`, and a refusal said in
 * the server's words (REQ-PLC-004).
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const DRAFT_URL = '/apps/opencatalogi/api/publications/pub-1/draft'

/**
 * Mount the widget for publication pub-1 in the given state and let its reads settle.
 *
 * @param {object} visibility The visibility answer.
 * @param {object} [mocks] Extra global mocks, such as a router.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(visibility, mocks = {}) {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({ data: visibility })
		}
		return Promise.resolve({ data: [] })
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', content: { title: 'Concept besluit' } },
		global: {
			mocks: { t: T, ...mocks },
			stubs: { WithdrawPublicationDialog: true, RepublishDocumentDialog: true, DeleteDraftPermanentlyDialog: true },
		},
	})
	await flushPromises()
	return wrapper
}

const BUTTON = '[data-testid="publication-delete-permanently"]'

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.delete.mockReset()
})

describe('PublicationVisibilityWidget Delete permanently', () => {
	it('shows for a draft and for a publication in review to a caller who may update it', async () => {
		for (const state of ['draft', 'in_review']) {
			const wrapper = await mountWidget({ state, canUpdate: true })
			expect(wrapper.find(BUTTON).exists()).toBe(true)
		}
	})

	it('does not show without the update right or once it was public', async () => {
		expect((await mountWidget({ state: 'draft', canUpdate: false })).find(BUTTON).exists()).toBe(false)
		expect((await mountWidget({ state: 'public', canUpdate: true })).find(BUTTON).exists()).toBe(false)
	})

	it('opens its dialog naming the publication and clears the previous message', async () => {
		const wrapper = await mountWidget({ state: 'draft', canUpdate: true })
		wrapper.vm.message = 'old'

		await wrapper.find(BUTTON).trigger('click')

		expect(wrapper.vm.purgeOpen).toBe(true)
		expect(wrapper.vm.message).toBe('')
		expect(wrapper.vm.publicationTitle).toBe('Concept besluit')
	})

	it('deletes the draft, says so and leaves for the catalog list', async () => {
		const push = jest.fn(() => Promise.resolve())
		const wrapper = await mountWidget(
			{ state: 'draft', canUpdate: true },
			{ $router: { push }, $route: { params: { catalogSlug: 'woo' } } },
		)
		axios.delete.mockResolvedValue({ data: { removed: [] } })
		wrapper.vm.purgeOpen = true

		await wrapper.vm.purge()

		expect(axios.delete).toHaveBeenCalledWith(DRAFT_URL)
		expect(wrapper.vm.purgeOpen).toBe(false)
		expect(wrapper.vm.state).toBe('')
		expect(wrapper.vm.messageType).toBe('success')
		expect(wrapper.vm.message).toBe('The publication was deleted permanently.')
		expect(push).toHaveBeenCalledWith({ name: 'Publications', params: { catalogSlug: 'woo' } })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('stays on the page when it knows no catalog', async () => {
		const push = jest.fn(() => Promise.resolve())
		const wrapper = await mountWidget(
			{ state: 'draft', canUpdate: true },
			{ $router: { push }, $route: { params: {} } },
		)
		axios.delete.mockResolvedValue({ data: { removed: [] } })

		await wrapper.vm.purge()

		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.message).toBe('The publication was deleted permanently.')
	})

	it('says a refusal in the server words and keeps the state', async () => {
		const wrapper = await mountWidget({ state: 'draft', canUpdate: true })
		axios.delete.mockRejectedValue({
			response: {
				status: 409,
				data: { error: 'released-before', message: 'This publication was published before, so it cannot be deleted permanently.' },
			},
		})

		await wrapper.vm.purge()

		expect(wrapper.vm.state).toBe('draft')
		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('This publication was published before, so it cannot be deleted permanently.')
		expect(wrapper.vm.purgeOpen).toBe(false)
	})

	it('asks to try again when the server gave no words', async () => {
		const wrapper = await mountWidget({ state: 'in_review', canUpdate: true })
		axios.delete.mockRejectedValue(new Error('network'))

		await wrapper.vm.purge()

		expect(wrapper.vm.message).toBe('That did not work. Try again.')
	})
})
