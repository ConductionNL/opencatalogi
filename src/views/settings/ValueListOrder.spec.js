/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `ValueListOrder.vue`: a value list other than the
 * information categories loads in the organisation's order, a move saves
 * the new order for that list and announces the entry's place, and a
 * refused read or save shows the reason.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */

import axios from '@nextcloud/axios'
import { CHOICES_URL, ORDER_URL } from './CategoryExplanations.vue'
import ValueListOrder from './ValueListOrder.vue'

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

const ADVICE = { uri: 'https://example.test/handeling/advies', label: 'Advies' }
const DECISION = {
	uri: 'https://example.test/handeling/besluit',
	label: 'Besluitvorming',
}

/**
 * A fresh component state for one list.
 *
 * @param {string} scheme The list.
 * @return {object} The state.
 */
function state(scheme = 'soortHandeling') {
	return {
		...ValueListOrder.methods,
		...ValueListOrder.data(),
		scheme,
		heading: 'Order',
	}
}

describe('ValueListOrder', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it("loads the named list in the organisation's order", async () => {
		axios.get.mockResolvedValue({
			data: { entries: [DECISION, ADVICE], choicesUnavailable: true },
		})
		const s = state('documentsoorten')
		await s.load()
		expect(axios.get).toHaveBeenCalledWith(CHOICES_URL, {
			params: { scheme: 'documentsoorten' },
		})
		expect(s.entries).toEqual([DECISION, ADVICE])
		expect(s.choicesUnavailable).toBe(true)
		expect(s.error).toBeNull()
	})

	it('shows the reason when the list cannot be read', async () => {
		axios.get.mockRejectedValue({
			response: { data: { error: 'Unknown value list.' } },
		})
		const s = state()
		s.entries = [ADVICE]
		await s.load()
		expect(s.entries).toEqual([])
		expect(s.error).toBe('Unknown value list.')
	})

	it('moves an entry up, saves the order for its own list and announces its place', async () => {
		axios.put.mockResolvedValue({
			data: { entries: [DECISION, ADVICE], choicesUnavailable: false },
		})
		const s = state()
		s.entries = [ADVICE, DECISION]
		await s.move(1, -1)
		expect(axios.put).toHaveBeenCalledWith(ORDER_URL, {
			scheme: 'soortHandeling',
			entries: [DECISION.uri, ADVICE.uri],
		})
		expect(s.entries).toEqual([DECISION, ADVICE])
		expect(s.announcement).toBe('Besluitvorming is now at position 1.')
		expect(s.reordering).toBe(false)
	})

	it('does not move past an end and shows a refused order', async () => {
		const s = state()
		s.entries = [ADVICE, DECISION]
		await s.move(0, -1)
		expect(axios.put).not.toHaveBeenCalled()

		axios.put.mockRejectedValue(new Error('Network Error'))
		await s.move(0, 1)
		expect(s.error).toBe('Network Error')
		expect(s.entries).toEqual([ADVICE, DECISION])
		expect(s.announcement).toBe('')
		expect(s.reordering).toBe(false)
	})

	it('reads an empty answer as an empty list', () => {
		const s = state()
		s.apply(null)
		expect(s.entries).toEqual([])
		expect(s.choicesUnavailable).toBe(false)
	})
})
