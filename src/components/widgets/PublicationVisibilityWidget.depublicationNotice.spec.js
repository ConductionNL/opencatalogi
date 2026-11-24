/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "The officer is warned at save" (REQ-PSG-003): setting a coming
 * depublication date on the open publication shows a notice naming it;
 * opening the page shows none.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const NOTICE = '[data-testid="publication-visibility-depublication-notice"]'
const FUTURE = '2999-12-01T00:00:00Z'

/**
 * Mount the widget for a public publication with the given properties.
 *
 * @param {object} content The publication's properties.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(content) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/visibility') ? { state: 'public', canPublish: true, canUpdate: true } : [],
	}))
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', content },
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

describe('PublicationVisibilityWidget depublication notice at save', () => {
	it('says nothing when the page opens on a publication that already has a date', async () => {
		expect((await mountWidget({ id: 'pub-1', title: 'Besluit', depublicationDate: FUTURE })).find(NOTICE).exists()).toBe(false)
	})

	it('names the date once the editor sets it, and clears it when the date is removed', async () => {
		const wrapper = await mountWidget({ id: 'pub-1', title: 'Besluit' })
		await wrapper.setProps({ content: { id: 'pub-1', title: 'Besluit', depublicationDate: FUTURE } })
		expect(wrapper.find(NOTICE).text()).toBe(`Saved: this publication will be taken down automatically on ${new Date(FUTURE).toLocaleDateString()}.`)

		await wrapper.setProps({ content: { id: 'pub-1', title: 'Besluit', depublicationDate: '' } })
		expect(wrapper.find(NOTICE).exists()).toBe(false)
	})
})
