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
	moveEntry,
	ORDER_URL,
	SCHEME,
} from './CategoryExplanations.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) =>
			key in vars ? String(vars[key]) : match,
		),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcNoteCard: {},
	NcTextArea: {},
}))

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
		expect(s.error).toBeNull()
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

	it('moves a category up and saves the new order, announcing its place', async () => {
		const first = { code: 'infocat001', uri: 'u1', label: 'Wetten' }
		const subsidies = {
			code: 'infocat013',
			uri: 'u13',
			label: 'Subsidieverplichtingen anders dan met beschikking',
		}
		axios.put.mockResolvedValue({
			data: { entries: [subsidies, first], choicesUnavailable: false },
		})
		const s = state()
		s.entries = [first, subsidies]
		await s.move(1, -1)
		expect(axios.put).toHaveBeenCalledWith(ORDER_URL, {
			scheme: SCHEME,
			entries: ['u13', 'u1'],
		})
		expect(s.entries).toEqual([subsidies, first])
		expect(s.announcement).toBe(
			'Subsidieverplichtingen anders dan met beschikking is now at position 1.',
		)
		expect(s.reordering).toBe(false)
	})

	it('does not move past either end, and shows a refused order', async () => {
		expect(moveEntry([{ uri: 'a' }], 0, -1)).toBeNull()
		expect(moveEntry([{ uri: 'a' }, { uri: 'b' }], 1, 1)).toBeNull()
		expect(
			moveEntry([{ uri: 'a' }, { uri: 'b' }, { uri: 'c' }], 0, 1).map(
				(e) => e.uri,
			),
		).toEqual(['b', 'a', 'c'])
		axios.put.mockRejectedValue({
			response: { data: { error: 'The choice could not be saved: nope' } },
		})
		const s = state()
		s.entries = [
			{ uri: 'a', label: 'A' },
			{ uri: 'b', label: 'B' },
		]
		await s.move(0, 1)
		expect(s.error).toBe('The choice could not be saved: nope')
		await s.move(0, -1)
		expect(axios.put).toHaveBeenCalledTimes(1)
	})

	it('hides a category, and shows the refusal when publications are filed under it', async () => {
		axios.put.mockResolvedValueOnce({
			data: {
				entries: [{ ...COVENANTS, hidden: true }],
				choicesUnavailable: false,
			},
		})
		const s = state()
		await s.hide(COVENANTS, true)
		expect(axios.put).toHaveBeenCalledWith(CHOICES_URL, {
			scheme: SCHEME,
			entry: COVENANTS.uri,
			choice: { hidden: true },
		})
		expect(s.entries[0].hidden).toBe(true)

		axios.put.mockRejectedValueOnce({
			response: {
				data: {
					error: 'The choice could not be saved: Move the 3 publications filed under this category before hiding it.',
				},
			},
		})
		await s.hide(COVENANTS, true)
		expect(s.error).toContain('3 publications')
		expect(s.saving).toBeNull()
	})
})
