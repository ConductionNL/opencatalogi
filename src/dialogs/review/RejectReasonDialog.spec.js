/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `RejectReasonDialog.vue` (woo-review-surface, REQ-WRV-002):
 * nothing goes back without a reason, and the reason goes back trimmed.
 */

import RejectReasonDialog from './RejectReasonDialog.vue'

jest.mock('@nextcloud/vue', () => ({}))

/**
 * A dialog state with its computed values and methods.
 *
 * @param {object} props The props.
 * @return {object} The state.
 */
function dialog(props = {}) {
	const emitted = []
	const state = {
		...RejectReasonDialog.data(),
		scope: 'batch',
		subject: '',
		...props,
		emitted,
		$emit: (name, payload) => emitted.push([name, payload]),
	}
	for (const [name, getter] of Object.entries(RejectReasonDialog.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state) })
	}
	for (const [name, method] of Object.entries(RejectReasonDialog.methods)) {
		state[name] = method.bind(state)
	}
	return state
}

describe('RejectReasonDialog', () => {
	it('hands back nothing without a reason', () => {
		const state = dialog()
		state.reason = '   '

		state.confirm()

		expect(state.emitted).toEqual([])
	})

	it('hands back the trimmed reason', () => {
		const state = dialog()
		state.reason = '  Bijlage 3 bevat nog een BSN '

		state.confirm()

		expect(state.emitted).toEqual([['confirm', 'Bijlage 3 bevat nog een BSN']])
	})

	it('names what goes back', () => {
		expect(dialog().title).toBe('Reject the batch')
		expect(dialog({ scope: 'document' }).title).toBe('Send the document back')
		expect(dialog({ scope: 'document' }).confirmLabel).toBe('Send back')
	})

	it('closes without rejecting', () => {
		const state = dialog()

		state.onOpenChange(false)

		expect(state.emitted).toEqual([['close', undefined]])
	})
})
