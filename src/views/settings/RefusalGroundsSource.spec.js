/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `RefusalGroundsSource.vue`: the settings say when the refusal
 * grounds are the vendored snapshot, say dossiq when they are dossiq's, and
 * show a read failure instead of either.
 */

import axios from '@nextcloud/axios'
import RefusalGroundsSource, { sourceOf } from './RefusalGroundsSource.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({ NcNoteCard: {} }))

/**
 * Run load on a fresh component state.
 *
 * @return {Promise<object>} The state afterwards.
 */
async function load() {
	const state = { ...RefusalGroundsSource.methods, source: 'old', error: 'old' }
	await RefusalGroundsSource.methods.load.call(state)
	return state
}

describe('RefusalGroundsSource', () => {
	beforeEach(() => axios.get.mockReset())

	it('says snapshot when the grounds come from the vendored copy', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ code: '5.1.2.e', source: 'snapshot' }] } })
		const state = await load()
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/weigeringsgronden')
		expect(state.source).toBe('snapshot')
		expect(state.error).toBeNull()
	})

	it('says dossiq when every ground is dossiq\'s', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ source: 'dossiq' }, { source: 'dossiq' }] } })
		expect((await load()).source).toBe('dossiq')
	})

	it('shows the failure, and no source, when the grounds cannot be read', async () => {
		axios.get.mockRejectedValue({ response: { data: { error: 'The refusal grounds cannot be read' } } })
		const state = await load()
		expect(state.source).toBeNull()
		expect(state.error).toBe('The refusal grounds cannot be read')
	})

	it('names no source for an empty or unmarked list', () => {
		expect(sourceOf([])).toBeNull()
		expect(sourceOf([{ source: 'dossiq' }, {}])).toBeNull()
		expect(sourceOf([{ source: 'dossiq' }, { source: 'snapshot' }])).toBe('snapshot')
	})
})
