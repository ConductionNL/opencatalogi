/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationAttachmentsWidget.vue`: Add attachment opens
 * the app's upload dialog for the page's publication, and the list is read
 * again when that dialog closes.
 */

// @nextcloud/auth ships ESM that Jest does not transform; the store only
// needs the request token.
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
// The order list reads the files; this spec only checks what it is handed.
jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(() => Promise.resolve({ data: [] })),
	patch: jest.fn(),
}))

import { flushPromises, mount } from '@vue/test-utils'
import PublicationAttachmentsWidget from './PublicationAttachmentsWidget.vue'
import { navigationStore, objectStore } from '../../store/store.js'

const publication = {
	id: 'pub-1',
	title: 'Besluit op het Woo-verzoek over windpark Oostpolder',
	'@self': { id: 'pub-1', register: 19, schema: 173 },
}

/**
 * @param {object} [context] The detail page's object context.
 * @return {object} The wrapper.
 */
function mountWidget(context = { objectId: 'pub-1', object: publication }) {
	return mount(PublicationAttachmentsWidget, {
		props: { content: { maxDisplay: 100 } },
		global: {
			provide: { cnObjectContext: context },
			stubs: {
				CnFilesCard: {
					name: 'CnFilesCard',
					props: [
						'register',
						'schema',
						'objectId',
						'title',
						'maxDisplay',
						'surface',
					],
					template: '<div class="files-card-stub" />',
				},
			},
		},
	})
}

describe('PublicationAttachmentsWidget', () => {
	let setActive

	beforeEach(() => {
		jest.spyOn(console, 'log').mockImplementation(() => {})
		setActive = jest
			.spyOn(objectStore, 'setActiveObject')
			.mockImplementation(() => {})
	})

	afterEach(() => {
		navigationStore.setDialog(false)
		jest.restoreAllMocks()
	})

	it('hands the order list the publication route and its stored order (REQ-PDP-001)', () => {
		const ordered = { ...publication, attachmentOrder: ['12', '11'] }
		const list = mountWidget({
			objectId: 'pub-1',
			object: ordered,
		}).findComponent({ name: 'AttachmentOrderList' })

		expect(list.props()).toEqual({
			route: { register: '19', schema: '173', id: 'pub-1' },
			order: ['12', '11'],
		})
	})

	it("lists the page's publication's files", () => {
		const card = mountWidget().findComponent({ name: 'CnFilesCard' })

		expect(card.props()).toMatchObject({
			register: '19',
			schema: '173',
			objectId: 'pub-1',
			maxDisplay: 100,
		})
	})

	it('opens the upload dialog for this publication', async () => {
		const wrapper = mountWidget()

		await wrapper
			.find('[data-testid="publication-attachments-add"]')
			.trigger('click')

		expect(setActive).toHaveBeenCalledWith('publication', publication)
		expect(navigationStore.dialog).toBe('uploadFiles')
	})

	it('reads the files again when the dialog closes', async () => {
		const wrapper = mountWidget()
		await wrapper
			.find('[data-testid="publication-attachments-add"]')
			.trigger('click')
		const before = wrapper.vm.refreshKey

		navigationStore.setDialog(null)
		await flushPromises()

		expect(wrapper.vm.refreshKey).toBe(before + 1)
	})
})
