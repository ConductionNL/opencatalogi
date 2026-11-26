/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Visibility card shows an error while the publication's depublication
 * date falls on or before its coming publication date, because it then never
 * goes live.
 *
 * @spec openspec/specs/publications/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const ERROR = '[data-testid="publication-visibility-invalid-window"]'
const AUTO_DEPUBLICATION = '[data-testid="publication-visibility-auto-depublication"]'

/**
 * Mount the widget for a scheduled publication with the given properties.
 *
 * @param {object} content The publication's properties.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(content) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/visibility') ? { state: 'scheduled', canPublish: true, canUpdate: true } : [],
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

describe('PublicationVisibilityWidget invalid window', () => {
	it('shows an error instead of the automatic depublication when the window closes before it opens', async () => {
		const wrapper = await mountWidget({ publicationDate: '2999-12-02T00:00:00Z', depublicationDate: '2999-12-01T00:00:00Z' })

		expect(wrapper.find(ERROR).text()).toBe('This publication never goes live: its depublication date is on or before its publication date. Change one of the dates.')
		expect(wrapper.find(AUTO_DEPUBLICATION).exists()).toBe(false)
	})

	it('says nothing for a window that opens before it closes', async () => {
		const wrapper = await mountWidget({ publicationDate: '2999-12-01T00:00:00Z', depublicationDate: '2999-12-02T00:00:00Z' })

		expect(wrapper.find(ERROR).exists()).toBe(false)
		expect(wrapper.find(AUTO_DEPUBLICATION).exists()).toBe(true)
	})

	it('says nothing once a scheduled publication was taken down', async () => {
		const wrapper = await mountWidget({ publicationDate: '2999-12-01T00:00:00Z', depublicationDate: '2020-01-01T00:00:00Z' })

		expect(wrapper.find(ERROR).exists()).toBe(false)
	})
})
