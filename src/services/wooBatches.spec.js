/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Add on the Woo batches page posts only the case reference, and a refusal
 * (a second open batch, an unknown case) reaches the dialog as its message.
 */

import { createWooBatchHandlers } from './wooBatches.js'

const generateUrl = (path) => path

describe('createWooBatch', () => {
	it('posts the trimmed case reference and answers the opened batch', async () => {
		const axios = { post: jest.fn().mockResolvedValue({ data: { id: 'b-1', caseReference: 'WOO-2026-031', documents: ['a', 'b'] } }) }

		const batch = await createWooBatchHandlers({ axios, generateUrl }).createWooBatch({ caseReference: ' WOO-2026-031 ', title: 'ignored' })

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/batches', { caseReference: 'WOO-2026-031' })
		expect(batch).toEqual({ id: 'b-1', caseReference: 'WOO-2026-031', documents: ['a', 'b'] })
	})

	it('throws the server refusal naming the open batch', async () => {
		const axios = { post: jest.fn().mockRejectedValue({ response: { status: 409, data: { error: 'Woo request WOO-2026-031 already has an open batch (b-1).' } } }) }

		await expect(createWooBatchHandlers({ axios, generateUrl }).createWooBatch({ caseReference: 'WOO-2026-031' }))
			.rejects.toThrow('Woo request WOO-2026-031 already has an open batch (b-1).')
	})

	it('falls back to the transport message when the server says nothing', async () => {
		const axios = { post: jest.fn().mockRejectedValue(new Error('Network Error')) }

		await expect(createWooBatchHandlers({ axios, generateUrl }).createWooBatch({})).rejects.toThrow('Network Error')
		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/batches', { caseReference: '' })
	})
})
