/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Put focus on the first tab of the dialog that now shows a created object.
 *
 * Creating an object swaps the create form for the edit tabs in the same
 * dialog. The control that had focus (Create) is gone with the form, so focus
 * fell to the page body, outside the dialog, and a keyboard user lost their
 * place (WCAG 2.4.3).
 *
 * @spec openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-the-officer-flow-works-from-the-keyboard-alone-req-ofa-002
 * @param {Document|Element} root Where to look for the open dialog.
 * @return {boolean} Whether a tab took focus.
 */
export function focusFirstTab(root) {
	const dialogs = Array.from(root.querySelectorAll('[role="dialog"]'))
	const dialog = dialogs[dialogs.length - 1]
	const tab = dialog && dialog.querySelector('[role="tab"]')
	if (!tab) {
		return false
	}
	tab.focus()
	return true
}
