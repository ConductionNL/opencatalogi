/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Before publishing, the Visibility card says how many documents the publish
 * would carry, files plus references, from the server's count (REQ-PSG-004).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'
import { documentCountOf } from '../../services/publicationVisibility.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const LINE = '[data-testid="publication-visibility-documents"]'

/**
 * Mount the widget with the given visibility answer.
 *
 * @param {object} answer The visibility body.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(answer) {
	axios.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/visibility') ? answer : [] }))
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

describe('PublicationVisibilityWidget document count', () => {
	it('shows the count beside Publish now', async () => {
		const wrapper = await mountWidget({ state: 'approved', canPublish: true, canUpdate: true, documents: 1 })
		expect(wrapper.find('[data-testid="publication-publish-now"]').exists()).toBe(true)
		expect(wrapper.find(LINE).text()).toBe('Documents to publish: 1')
	})

	it('shows zero too, the case the warning is for', async () => {
		expect((await mountWidget({ state: 'approved', canPublish: true, canUpdate: true, documents: 0 })).find(LINE).text())
			.toBe('Documents to publish: 0')
	})

	it('shows nothing without a count or without a publish move', async () => {
		expect((await mountWidget({ state: 'approved', canPublish: true, canUpdate: true })).find(LINE).exists()).toBe(false)
		expect((await mountWidget({ state: 'public', canPublish: true, canUpdate: true, documents: 3 })).find(LINE).exists()).toBe(false)
	})
})

describe('documentCountOf', () => {
	it('answers a whole non-negative count, else null', () => {
		expect(documentCountOf({ documents: 2 })).toBe(2)
		expect(documentCountOf({ documents: 0 })).toBe(0)
		expect(documentCountOf({ documents: -1 })).toBeNull()
		expect(documentCountOf({ documents: '2' })).toBeNull()
		expect(documentCountOf({})).toBeNull()
		expect(documentCountOf(null)).toBeNull()
	})
})
