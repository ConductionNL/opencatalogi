/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `BatchReviewPanel.vue` (woo-review-surface, REQ-WRV-001,
 * REQ-WRV-002): the public views load, opening one records it, approve
 * waits until every one was opened, and a reason travels with a rejection
 * or a single document's send-back.
 */

import axios from '@nextcloud/axios'
import BatchReviewPanel from './BatchReviewPanel.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

/**
 * A panel state with its methods bound.
 *
 * @return {object} The state.
 */
function panel() {
	const emitted = []
	const state = {
		...BatchReviewPanel.data(),
		batchId: 'batch-1',
		caseReference: 'WOO-2026-7',
		emitted,
		$emit: (name, payload) => emitted.push([name, payload]),
	}
	for (const [name, method] of Object.entries(BatchReviewPanel.methods)) {
		state[name] = method.bind(state)
	}
	return state
}

const PREVIEW = {
	documents: [
		{
			documentId: 'doc-open',
			title: 'Besluit.pdf',
			assessment: 'openbaar',
			viewerUrl: '/f/11',
			publicMetadata: {
				title: 'Woo-besluit',
				documentTitle: 'Besluit.pdf',
				caseReference: 'WOO-2026-7',
			},
		},
		{
			documentId: 'doc-part',
			title: 'Bijlage.pdf',
			assessment: 'deels_openbaar',
			viewerUrl: '/f/13',
			publicMetadata: { title: 'Woo-besluit' },
			redactionLog: [],
		},
	],
	unseen: [
		{ documentId: 'doc-open', title: 'Besluit.pdf' },
		{ documentId: 'doc-part', title: 'Bijlage.pdf' },
	],
}

describe('BatchReviewPanel', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('loads each document as the public will see it', async () => {
		axios.get.mockResolvedValue({ data: PREVIEW })
		const state = panel()

		await state.load()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/public-preview',
		)
		expect(state.documents.map((d) => d.documentId)).toEqual([
			'doc-open',
			'doc-part',
		])
		expect(state.isSeen(state.documents[0])).toBe(false)
		expect(state.loading).toBe(false)
	})

	it('records an opened public view and keeps what is still unseen', async () => {
		axios.get.mockResolvedValue({ data: PREVIEW })
		axios.post.mockResolvedValue({
			data: { unseen: [{ documentId: 'doc-part', title: 'Bijlage.pdf' }] },
		})
		const state = panel()
		await state.load()

		await state.markSeen(state.documents[0])

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/preview-seen',
			{ documentId: 'doc-open' },
		)
		expect(state.isSeen(state.documents[0])).toBe(true)
		expect(state.isSeen(state.documents[1])).toBe(false)
	})

	it('shows the public metadata in a fixed order and leaves out empty fields', () => {
		const state = panel()

		expect(
			state
				.metadataFields({
					caseReference: 'WOO-2026-7',
					title: 'Woo-besluit',
					documentTitle: 'Besluit.pdf',
					wooCategory: '',
				})
				.map((f) => f.key),
		).toEqual(['documentTitle', 'title', 'caseReference'])
	})

	it('shows the refusal of approve and tells the page nothing changed', async () => {
		axios.post.mockRejectedValue({
			response: {
				data: {
					error: 'Approve refused: the public view of these documents was not opened: Bijlage.pdf',
				},
			},
		})
		const state = panel()

		await state.approve()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/approve',
		)
		expect(state.error).toContain('Bijlage.pdf')
		expect(state.emitted).toEqual([])
		expect(state.busy).toBe(false)
	})

	it('rejects the batch with the reason and tells the page', async () => {
		axios.post.mockResolvedValue({ data: { status: 'in_progress' } })
		const state = panel()
		state.dialog = { scope: 'batch' }

		await state.onReason('Bijlage 3 bevat nog een BSN')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/reject',
			{ reason: 'Bijlage 3 bevat nog een BSN' },
		)
		expect(state.emitted).toEqual([['changed', undefined]])
		expect(state.dialog).toBe(null)
	})

	it('sends one document back with its batch and the reason', async () => {
		axios.post.mockResolvedValue({ data: {} })
		axios.get.mockResolvedValue({ data: PREVIEW })
		const state = panel()
		state.dialog = { scope: 'document', doc: PREVIEW.documents[1] }

		await state.onReason('Naam op pagina 4 niet gelakt')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/assessments/doc-part/send-back',
			{ batchId: 'batch-1', reason: 'Naam op pagina 4 niet gelakt' },
		)
		expect(axios.get).toHaveBeenCalled()
	})
})
