/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `WooIndexConnection.vue`'s requestRegistration: a success
 * stores the registration; a refusal that carries the unsent request shows
 * it, any other failure shows the fixed message.
 */

import axios from '@nextcloud/axios'
import WooIndexConnection from './WooIndexConnection.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {} }))

/**
 * Run requestRegistration on a fresh component state.
 *
 * @return {Promise<object>} The state afterwards.
 */
async function request() {
	const state = {
		...WooIndexConnection.methods,
		busy: false,
		unsent: 'old',
		error: 'old',
		registration: null,
		t: (app, text) => text,
	}
	await WooIndexConnection.methods.requestRegistration.call(state)
	return state
}

describe('WooIndexConnection requestRegistration', () => {
	beforeEach(() => axios.post.mockReset())

	it('stores the registration and clears the messages', async () => {
		axios.post.mockResolvedValue({ data: { registration: { id: 'r1' } } })
		const state = await request()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/registration')
		expect(state).toMatchObject({ busy: false, unsent: '', error: '', registration: { id: 'r1' } })
	})

	it('shows the request that could not be sent', async () => {
		axios.post.mockRejectedValue({ response: { data: { request: { a: 1 } } } })
		const state = await request()

		expect(state.unsent).toBe(JSON.stringify({ a: 1 }, null, 2))
		expect(state.error).toBe('')
		expect(state.busy).toBe(false)
	})

	it.each([
		['no response', {}],
		['a response without data', { response: {} }],
		['data without a request', { response: { data: { message: 'x' } } }],
		['no error at all', null],
	])('shows the fixed message on %s', async (label, err) => {
		axios.post.mockRejectedValue(err)
		const state = await request()

		expect(state.error).toBe('The registration could not be requested. Try again.')
		expect(state.unsent).toBe('')
		expect(state.busy).toBe(false)
	})
})
