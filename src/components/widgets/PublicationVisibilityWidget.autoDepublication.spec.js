/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Visibility card says "Automatic depublication on <date>" while the
 * publication's depublication date lies ahead, and nothing otherwise
 * (REQ-PSG-003).
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
const LINE = '[data-testid="publication-visibility-auto-depublication"]'

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

describe('PublicationVisibilityWidget automatic depublication', () => {
	it('shows the coming depublication date', async () => {
		const future = '2999-12-01T00:00:00Z'
		const wrapper = await mountWidget({ title: 'Besluit', depublicationDate: future })
		expect(wrapper.find(LINE).text()).toBe(`Automatic depublication on ${new Date(future).toLocaleDateString()}`)
	})

	it('says nothing for a past date or without one', async () => {
		expect((await mountWidget({ title: 'Besluit', depublicationDate: '2000-01-01T00:00:00Z' })).find(LINE).exists()).toBe(false)
		expect((await mountWidget({ title: 'Besluit' })).find(LINE).exists()).toBe(false)
	})
})
