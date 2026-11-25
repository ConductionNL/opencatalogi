/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `CategoryExplanations.vue`: the categories load with the
 * organisation's choices, an explanation is saved as a value-list choice,
 * and a refused save shows the reason.
 */

import axios from '@nextcloud/axios'
import CategoryExplanations, {
	CHOICES_URL,
	draftOf,
	SCHEME,
} from './CategoryExplanations.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {}, NcTextArea: {} }))

const COVENANTS = {
	code: 'infocat011',
	uri: 'https://identifier.overheid.nl/tooi/def/thes/kern/c_8fc2335c',
	label: 'Convenanten',
	explanation: '',
}

/**
 * A fresh component state.
 *
 * @return {object} The state.
 */
function state() {
	const fresh = {
		...CategoryExplanations.methods,
		...CategoryExplanations.data(),
	}
	return fresh
}

describe('CategoryExplanations', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it("loads the categories with the organisation's choices", async () => {
		axios.get.mockResolvedValue({
			data: { entries: [COVENANTS], choicesUnavailable: true },
		})
		const s = state()
		await s.load()
		expect(axios.get).toHaveBeenCalledWith(CHOICES_URL, {
			params: { scheme: SCHEME },
		})
		expect(s.entries).toEqual([COVENANTS])
		expect(s.choicesUnavailable).toBe(true)
		expect(s.error).toBeNull()
	})

	it('saves the explanation as a choice about the entry', async () => {
		const saved = { ...COVENANTS, explanation: 'Afspraken met andere partijen.' }
		axios.put.mockResolvedValue({
			data: { entries: [saved], choicesUnavailable: false },
		})
		const s = state()
		s.entries = [COVENANTS]
		s.setDraft(COVENANTS, 'Afspraken met andere partijen.')
		await s.save(COVENANTS)
		expect(axios.put).toHaveBeenCalledWith(CHOICES_URL, {
			scheme: SCHEME,
			entry: COVENANTS.uri,
			choice: { explanation: 'Afspraken met andere partijen.' },
		})
		expect(s.entries).toEqual([saved])
		expect(s.drafts).toEqual({})
		expect(s.saving).toBeNull()
	})

	it('shows the reason when a save is refused', async () => {
		axios.put.mockRejectedValue({
			response: {
				data: { error: 'An explanation is at most 1000 characters.' },
			},
		})
		const s = state()
		await s.save(COVENANTS)
		expect(s.error).toBe('An explanation is at most 1000 characters.')
		expect(s.saving).toBeNull()
	})

	it('edits a draft and falls back to the saved explanation', () => {
		expect(draftOf({}, { uri: 'a', explanation: 'Saved' })).toBe('Saved')
		expect(draftOf({ a: '' }, { uri: 'a', explanation: 'Saved' })).toBe('')
		expect(draftOf({}, { uri: 'a' })).toBe('')
	})
})
