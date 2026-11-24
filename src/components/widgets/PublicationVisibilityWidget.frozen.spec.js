/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A frozen publication on its page (REQ-PWA-003): the Visibility card says
 * when, by whom and why it was frozen, and offers no Republish for a
 * withdrawn document, because OpenRegister refuses that file write.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.4
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const FILES_URL = '/apps/openregister/api/objects/reg-1/pubs/pub-1/files'
const FROZEN_AT = '2026-11-20T09:00:00+00:00'

/**
 * Mount the widget for publication pub-1 with the page's object, and let its reads settle.
 *
 * @param {object} object The publication as the page read it.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(object) {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({
				data: { state: 'withdrawn', canPublish: true, canUpdate: true },
			})
		}
		if (url === FILES_URL) {
			return Promise.resolve({
				data: {
					results: [
						{
							id: 12,
							title: 'Bijlage.pdf',
							depublished: '2026-10-01T10:00:00+00:00',
						},
					],
				},
			})
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs' },
		global: {
			mocks: { t: T },
			provide: { cnObjectContext: { objectId: 'pub-1', object } },
			stubs: {
				WithdrawPublicationDialog: true,
				RepublishDocumentDialog: true,
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

describe('a frozen publication on its page', () => {
	it('says when, by whom and why it was frozen', async () => {
		const wrapper = await mountWidget({
			id: 'pub-1',
			'@self': {
				frozen: {
					by: 'admin',
					at: FROZEN_AT,
					reason: 'Bevat een woonadres',
					state: 'withdrawn',
				},
			},
		})

		const note = wrapper.find('[data-testid="publication-frozen"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain(
			'Frozen on ' + new Date(FROZEN_AT).toLocaleString() + ' by admin',
		)
		expect(note.text()).toContain('Reason: Bevat een woonadres')
	})

	it('names an unknown user and an unrecorded date when the marker lacks them, and leaves out the reason line', async () => {
		const wrapper = await mountWidget({
			id: 'pub-1',
			'@self': { frozen: { state: 'withdrawn' } },
		})

		const note = wrapper.find('[data-testid="publication-frozen"]').text()
		expect(note).toContain('Frozen on an unrecorded date by an unknown user')
		expect(note).not.toContain('Reason:')
	})

	it('offers no Republish for a withdrawn document while frozen', async () => {
		const wrapper = await mountWidget({
			id: 'pub-1',
			'@self': { frozen: { by: 'admin', at: FROZEN_AT } },
		})

		expect(
			wrapper.find('[data-testid="publication-withdrawn-document"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="publication-republish-document"]').exists(),
		).toBe(false)
	})

	it('shows no note and keeps Republish when the publication is not frozen', async () => {
		const wrapper = await mountWidget({ id: 'pub-1', '@self': { frozen: null } })

		expect(wrapper.find('[data-testid="publication-frozen"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="publication-republish-document"]').exists(),
		).toBe(true)
	})
})
