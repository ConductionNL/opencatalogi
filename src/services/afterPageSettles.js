/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Resolves once the page has had time to load what it shows: 2 s, then the
 * browser's next idle moment (at most 3 s more).
 *
 * @param {number} [delayMs] How long to wait before asking for an idle moment.
 * @return {Promise<void>}
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export function afterPageSettles(delayMs = 2000) {
	return new Promise((resolve) => {
		setTimeout(() => {
			if (typeof window.requestIdleCallback === 'function') {
				window.requestIdleCallback(() => resolve(), { timeout: 3000 })
			} else {
				resolve()
			}
		}, delayMs)
	})
}
