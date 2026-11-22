/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Jest mock for `@nextcloud/dialogs`.
 *
 * The real package ships ESM only, which this Jest setup does not transform,
 * so a spec that imports a component using a toast failed to load at all. A
 * spec that asserts on a toast still mocks the module itself; this stub only
 * lets the others load.
 */
module.exports = {
	showError: jest.fn(),
	showSuccess: jest.fn(),
	showWarning: jest.fn(),
	showInfo: jest.fn(),
	showMessage: jest.fn(),
}
