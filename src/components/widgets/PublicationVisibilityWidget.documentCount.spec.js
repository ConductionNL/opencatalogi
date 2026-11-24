/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The document count on the Visibility card: shown while Publish now or
 * Publish again is offered, and a zero count asks for the confirmation before
 * anything is sent (REQ-PSG-004).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	delete: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const PUBLISH_URL = '/apps/opencatalogi/api/publications/pub-1/publish'
const COUNT = '[data-testid="publication-visibility-documents"]'

/**
 * Mount the widget for publication pub-1 with the given visibility answer.
 *
 * @param {object} visibility The server's visibility answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(visibility) {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({ data: visibility })
		}
		return Promise.resolve({ data: [] })
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: {
			objectId: 'pub-1',
			register: 'reg-1',
			schema: 'pubs',
			content: { title: 'Besluit parkeren' },
		},
		global: {
			mocks: { t: T },
			stubs: {
				WithdrawPublicationDialog: true,
				RepublishDocumentDialog: true,
				DeleteDraftPermanentlyDialog: true,
				PublishWithoutDocumentsDialog: true,
				ReasonDialog: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PublicationVisibilityWidget document count', () => {
	it('shows how many documents go with the publication and publishes without asking', async () => {
		const wrapper = await mountWidget({
			state: 'approved',
			canPublish: true,
			documents: 1,
		})
		axios.post.mockResolvedValue({ data: {} })

		expect(wrapper.find(COUNT).text()).toBe('Documents published with it: 1')

		await wrapper
			.find('[data-testid="publication-publish-now"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(PUBLISH_URL)
		expect(wrapper.vm.noDocumentsOpen).toBe(false)
		expect(wrapper.vm.state).toBe('public')
	})

	it('asks for the confirmation before sending anything when the count is zero', async () => {
		const wrapper = await mountWidget({
			state: 'approved',
			canPublish: true,
			documents: 0,
		})

		expect(wrapper.find(COUNT).text()).toBe('Documents published with it: 0')

		await wrapper
			.find('[data-testid="publication-publish-now"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.noDocumentsOpen).toBe(true)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('sends the confirmed publish once the editor confirms a zero count', async () => {
		const wrapper = await mountWidget({
			state: 'withdrawn',
			canPublish: true,
			documents: 0,
		})
		axios.post.mockResolvedValue({ data: {} })

		await wrapper.vm.publish(true)

		expect(axios.post).toHaveBeenCalledWith(PUBLISH_URL, {
			confirmNoDocuments: true,
		})
		expect(wrapper.vm.state).toBe('public')
	})

	it('shows no count where Publish does not apply, or without a count', async () => {
		const published = await mountWidget({
			state: 'public',
			canPublish: true,
			documents: 3,
		})
		expect(published.find(COUNT).exists()).toBe(false)

		const unknown = await mountWidget({ state: 'approved', canPublish: true })
		expect(unknown.find(COUNT).exists()).toBe(false)
		expect(unknown.vm.documentCount).toBe(null)

		const odd = await mountWidget({
			state: 'approved',
			canPublish: true,
			documents: '2',
		})
		expect(odd.vm.documentCount).toBe(null)
	})

	it('forgets the count when the visibility read fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(PublicationVisibilityWidget, {
			props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs' },
			global: {
				mocks: { t: T },
				stubs: { WithdrawPublicationDialog: true, ReasonDialog: true },
			},
		})
		wrapper.vm.documentCount = 4
		await flushPromises()

		expect(wrapper.vm.documentCount).toBe(null)
	})
})
