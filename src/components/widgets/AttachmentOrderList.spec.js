/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `AttachmentOrderList.vue`: the documents in the stored
 * order, Move up and Move down with an announcement, and Save order writing
 * `attachmentOrder` (REQ-PDP-001).
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-an-officer-orders-a-publication-s-documents-and-the-public-list-keeps-that-order-req-pdp-001
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import AttachmentOrderList from './AttachmentOrderList.vue'
import { moveDocument, orderedDocuments } from '../../services/attachmentOrder.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), patch: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const ROUTE = { register: 'reg-1', schema: 'pubs', id: 'pub-1' }
const FILES = [
	{ id: 11, title: 'Bijlage 2' },
	{ id: 12, title: 'Besluit' },
	{ id: 13, title: 'Bijlage 1' },
]

/**
 * @param {Array<string>} order The stored order.
 * @return {Promise<object>} The mounted wrapper, files read.
 */
async function mountList(order = []) {
	axios.get.mockResolvedValue({ data: { results: FILES } })
	const wrapper = mount(AttachmentOrderList, { props: { route: ROUTE, order } })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper The wrapper.
 * @return {Array<string>} The labels in the list.
 */
function labels(wrapper) {
	return wrapper.findAll('.attachment-order__label').map((node) => node.text())
}

/**
 * @param {object} wrapper The wrapper.
 * @param {number} index The row.
 * @param {string} direction `up` or `down`.
 * @return {Promise<void>}
 */
async function press(wrapper, index, direction) {
	await wrapper
		.findAll('.attachment-order__item')
		[index].find(`[data-testid="attachment-order-${direction}"]`)
		.trigger('click')
}

describe('AttachmentOrderList', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('reads the files and shows them in the stored order, the rest after', async () => {
		const wrapper = await mountList(['13', '99'])

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/reg-1/pubs/pub-1/files',
		)
		expect(labels(wrapper)).toEqual(['Bijlage 1', 'Bijlage 2', 'Besluit'])
	})

	it('moves by the buttons, names each button after its document and announces the new place', async () => {
		const wrapper = await mountList()

		const up = wrapper
			.findAll('.attachment-order__item')[1]
			.find('[data-testid="attachment-order-up"]')
		expect(up.attributes('aria-label')).toBe('Move Besluit up')
		await press(wrapper, 1, 'up')
		await press(wrapper, 2, 'up')

		expect(labels(wrapper)).toEqual(['Besluit', 'Bijlage 1', 'Bijlage 2'])
		expect(wrapper.find('[data-testid="attachment-order-live"]').text()).toBe(
			'Bijlage 1 is now number 2 of 3',
		)
		expect(
			wrapper
				.find('[data-testid="attachment-order-live"]')
				.attributes('aria-live'),
		).toBe('polite')
	})

	it('saves the order on the publication', async () => {
		axios.patch.mockResolvedValue({ data: {} })
		const wrapper = await mountList()
		expect(
			wrapper
				.find('[data-testid="attachment-order-save"]')
				.attributes('disabled'),
		).toBeDefined()

		await press(wrapper, 1, 'up')
		await press(wrapper, 2, 'up')
		await wrapper.find('[data-testid="attachment-order-save"]').trigger('click')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/reg-1/pubs/pub-1',
			{ attachmentOrder: ['12', '13', '11'] },
		)
		expect(wrapper.text()).toContain('The order is saved.')
	})

	it('says so when the order cannot be saved', async () => {
		axios.patch.mockRejectedValue(new Error('403'))
		const wrapper = await mountList()

		await press(wrapper, 0, 'down')
		await wrapper.find('[data-testid="attachment-order-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The order could not be saved. Try again.')
	})

	it('shows nothing for a single document', async () => {
		axios.get.mockResolvedValue({ data: [FILES[0]] })
		const wrapper = mount(AttachmentOrderList, { props: { route: ROUTE } })
		await flushPromises()

		expect(wrapper.find('[data-testid="attachment-order"]').exists()).toBe(false)
	})
})

describe('attachment order helpers', () => {
	it('skips stored ids that are no longer attached', () => {
		expect(
			orderedDocuments([{ id: 1, label: 'a' }], ['9', '1']).map(
				(doc) => doc.id,
			),
		).toEqual(['1'])
	})

	it('does not move past either end', () => {
		const docs = [{ id: '1' }, { id: '2' }]
		expect(moveDocument(docs, 0, -1)).toEqual(docs)
		expect(moveDocument(docs, 1, 1)).toEqual(docs)
		expect(moveDocument(docs, 0, 1).map((doc) => doc.id)).toEqual(['2', '1'])
	})
})
