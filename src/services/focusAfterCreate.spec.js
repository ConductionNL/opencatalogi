/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * After Create, focus lands on the first tab of the dialog, not on the page body
 * (officer-flow-accessibility, REQ-OFA-002).
 *
 * @spec openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-the-officer-flow-works-from-the-keyboard-alone-req-ofa-002
 */
import { focusFirstTab } from './focusAfterCreate.js'

describe('focusFirstTab', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('focuses the first tab of the last open dialog', () => {
		document.body.innerHTML = '<div role="dialog"><button role="tab">Other</button></div>'
			+ '<div role="dialog"><button role="tab" id="first">Properties</button><button role="tab">Files</button></div>'
		expect(focusFirstTab(document)).toBe(true)
		expect(document.activeElement.id).toBe('first')
	})

	it('answers false when no dialog holds a tab', () => {
		document.body.innerHTML = '<div role="dialog"><button>Close</button></div>'
		expect(focusFirstTab(document)).toBe(false)
	})
})
