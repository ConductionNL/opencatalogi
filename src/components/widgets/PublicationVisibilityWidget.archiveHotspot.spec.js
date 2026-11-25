/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Visibility card says "Kept permanently: archive hotspot <subject>" when
 * the server names the archive-hotspot subject that keeps the publication, and
 * nothing otherwise (theme-archive-hotspot REQ-THA-003).
 *
 * @spec openspec/changes/archive/2026-11-25-theme-archive-hotspot/tasks.md#task-3.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const LINE = '[data-testid="publication-visibility-archive-hotspot"]'

/**
 * Mount the widget with the given visibility answer, or a failing one.
 *
 * @param {object|null} answer The visibility body, or null to fail the request.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(answer) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/visibility')) {
			return answer === null ? Promise.reject(new Error('offline')) : Promise.resolve({ data: answer })
		}
		return Promise.resolve({ data: [] })
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', content: { title: 'Besluit' } },
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

describe('PublicationVisibilityWidget archive hotspot', () => {
	it('names the subject that keeps the publication permanently', async () => {
		const wrapper = await mountWidget({ state: 'public', canPublish: true, canUpdate: true, archiveHotspot: 'Herindeling gemeente' })
		expect(wrapper.find(LINE).text()).toBe('Kept permanently: archive hotspot Herindeling gemeente')
	})

	it('says nothing without a hotspot or when the state cannot be read', async () => {
		expect((await mountWidget({ state: 'public', canPublish: true, canUpdate: true })).find(LINE).exists()).toBe(false)
		expect((await mountWidget(null)).find(LINE).exists()).toBe(false)
	})
})
