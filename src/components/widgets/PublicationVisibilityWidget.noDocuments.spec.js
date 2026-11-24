/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publish now on a publication with no documents: the server's 409
 * `no-documents` opens the confirmation dialog instead of reporting a
 * conflict, and the confirmed publish sends `confirmNoDocuments: true`
 * (REQ-PSG-004).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
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
const PUBLISH_URL = '/apps/opencatalogi/api/publications/pub-1/publish'
const NO_DOCUMENTS = { response: { status: 409, data: { error: 'no-documents', documents: 0 } } }

/**
 * Mount the widget for an approved publication pub-1 a publisher may publish.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget() {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({ data: { state: 'approved', canPublish: true, canUpdate: true } })
		}
		return Promise.resolve({ data: [] })
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', content: { title: 'Besluit parkeren' } },
		global: {
			mocks: { t: T },
			stubs: {
				WithdrawPublicationDialog: true,
				RepublishDocumentDialog: true,
				DeleteDraftPermanentlyDialog: true,
				PublishWithoutDocumentsDialog: true,
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

describe('PublicationVisibilityWidget publishing without documents', () => {
	it('asks a second time instead of reporting a conflict', async () => {
		const wrapper = await mountWidget()
		axios.post.mockRejectedValue(NO_DOCUMENTS)

		await wrapper.find('[data-testid="publication-publish-now"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(PUBLISH_URL)
		expect(wrapper.vm.noDocumentsOpen).toBe(true)
		expect(wrapper.vm.message).toBe('')
		expect(wrapper.vm.state).toBe('approved')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('publishes with the confirmation once the editor confirms', async () => {
		const wrapper = await mountWidget()
		wrapper.vm.noDocumentsOpen = true
		axios.post.mockResolvedValue({ data: {} })

		await wrapper.vm.publish(true)

		expect(axios.post).toHaveBeenCalledWith(PUBLISH_URL, { confirmNoDocuments: true })
		expect(wrapper.vm.noDocumentsOpen).toBe(false)
		expect(wrapper.vm.state).toBe('public')
		expect(wrapper.vm.message).toBe('The publication is public.')
	})

	it('closes the dialog and says a later refusal', async () => {
		const wrapper = await mountWidget()
		wrapper.vm.noDocumentsOpen = true
		axios.post.mockRejectedValue({ response: { status: 500, data: {} } })

		await wrapper.vm.publish(true)

		expect(wrapper.vm.noDocumentsOpen).toBe(false)
		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('That did not work. Try again.')
	})

	it('names the publication in the dialog', async () => {
		const wrapper = await mountWidget()
		expect(wrapper.vm.publicationTitle).toBe('Besluit parkeren')
	})
})
