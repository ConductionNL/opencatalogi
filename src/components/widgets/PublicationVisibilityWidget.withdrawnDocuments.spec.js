/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The withdrawn documents on the publication page (REQ-ATT-103, REQ-ATT-104):
 * a file whose own window has ended is listed as withdrawn with its date and
 * the reason kept on it, is no longer offered for withdrawal, and is put back
 * through the republish endpoint with a reason.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const FILES_URL = '/apps/openregister/api/objects/reg-1/pubs/pub-1/files'
const WITHDRAWN_AT = '2026-10-01T10:00:00+00:00'

let files

/**
 * Mount the widget for publication pub-1, answering its two reads, and let them settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget() {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({ data: { state: 'public' } })
		}
		if (url === FILES_URL) {
			return Promise.resolve({ data: { results: files } })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs' },
		global: {
			mocks: { t: T },
			stubs: { WithdrawPublicationDialog: true, RepublishDocumentDialog: true },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	files = [
		{ id: 11, title: 'Besluit.pdf' },
		{ id: 12, title: 'Bijlage.pdf', depublished: WITHDRAWN_AT, 'withdrawn-reason': 'Bevat een woonadres' },
		{ id: 13, name: 'later.pdf', depublished: '2999-01-01T00:00:00+00:00' },
	]
})

describe('withdrawn documents on the publication page', () => {
	it('lists only the file whose window has ended, with its date and reason', async () => {
		const wrapper = await mountWidget()

		const rows = wrapper.findAll('[data-testid="publication-withdrawn-document"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('Bijlage.pdf')
		expect(rows[0].find('[data-testid="publication-withdrawn-date"]').text())
			.toBe('Withdrawn on ' + new Date(WITHDRAWN_AT).toLocaleDateString())
		expect(rows[0].find('[data-testid="publication-withdrawn-reason"]').text()).toBe('Reason: Bevat een woonadres')
	})

	it('offers only the documents not withdrawn already to the withdraw dialog', async () => {
		const wrapper = await mountWidget()

		const offered = wrapper.findComponent({ name: 'WithdrawPublicationDialog' }).props('documents')
		expect(offered.map((document) => document.id)).toEqual([11, 13])
	})

	it('shows no withdrawn section and no reason line when nothing says so', async () => {
		files = [{ id: 11, title: 'Besluit.pdf' }]
		let wrapper = await mountWidget()
		expect(wrapper.find('[data-testid="publication-withdrawn-documents"]').exists()).toBe(false)

		files = [{ id: 12, title: 'Bijlage.pdf', depublished: WITHDRAWN_AT }]
		wrapper = await mountWidget()
		expect(wrapper.find('[data-testid="publication-withdrawn-reason"]').exists()).toBe(false)
	})

	it('puts a document back through the republish endpoint with the reason, then reads the files again', async () => {
		const wrapper = await mountWidget()
		await wrapper.find('[data-testid="publication-republish-document"]').trigger('click')
		const dialog = wrapper.findComponent({ name: 'RepublishDocumentDialog' })
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('document')).toMatchObject({ id: 12, label: 'Bijlage.pdf' })

		axios.post.mockResolvedValue({ data: {} })
		axios.get.mockClear()
		dialog.vm.$emit('confirm', { reason: 'Woonadres is weggelakt' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/publications/pub-1/files/12/republish',
			{ reason: 'Woonadres is weggelakt' },
		)
		expect(wrapper.find('[data-testid="publication-visibility-message"]').text()).toBe('The document is public again.')
		expect(axios.get).toHaveBeenCalledWith(FILES_URL)
		expect(wrapper.findComponent({ name: 'RepublishDocumentDialog' }).props('open')).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('says the user may not change the publication when the put-back is refused', async () => {
		const wrapper = await mountWidget()
		await wrapper.find('[data-testid="publication-republish-document"]').trigger('click')

		axios.post.mockRejectedValue({ response: { status: 403 } })
		wrapper.findComponent({ name: 'RepublishDocumentDialog' }).vm.$emit('confirm', { reason: 'x' })
		await flushPromises()

		expect(wrapper.find('[data-testid="publication-visibility-message"]').text()).toBe('You may not change this publication.')
		expect(wrapper.findComponent({ name: 'RepublishDocumentDialog' }).props('open')).toBe(false)
	})

	it('reads the files again after one document is withdrawn, so it shows as withdrawn', async () => {
		const wrapper = await mountWidget()
		axios.post.mockResolvedValue({ data: {} })
		axios.get.mockClear()

		wrapper.findComponent({ name: 'WithdrawPublicationDialog' }).vm.$emit('confirm', { reason: 'BSN', fileId: '11' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/publications/pub-1/files/11/withdraw', { reason: 'BSN' })
		expect(axios.get).toHaveBeenCalledWith(FILES_URL)
	})

	it('sends the public reason and the title choice with a whole withdrawal, never with a document', async () => {
		const wrapper = await mountWidget()
		axios.post.mockResolvedValue({ data: {} })
		const dialog = wrapper.findComponent({ name: 'WithdrawPublicationDialog' })

		dialog.vm.$emit('confirm', { reason: 'AVG-melding', fileId: '', publicReason: 'In strijd met de AVG', tombstoneShowsTitle: true })
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/publications/pub-1/withdraw', {
			reason: 'AVG-melding',
			publicReason: 'In strijd met de AVG',
			tombstoneShowsTitle: true,
		})

		dialog.vm.$emit('confirm', { reason: 'AVG-melding', fileId: '', publicReason: '', tombstoneShowsTitle: false })
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/publications/pub-1/withdraw', { reason: 'AVG-melding', tombstoneShowsTitle: false })

		dialog.vm.$emit('confirm', { reason: 'BSN', fileId: '11', publicReason: 'x', tombstoneShowsTitle: true })
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/publications/pub-1/files/11/withdraw', { reason: 'BSN' })
	})

	it('shows an unreadable date as it came', async () => {
		const wrapper = await mountWidget()
		expect(wrapper.vm.formatDate('not-a-date')).toBe('not-a-date')
	})
})
