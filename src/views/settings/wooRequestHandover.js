/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings lines for the Woo requests handed to dossiq
 * (woo-request-intake-hands-over-to-dossiq, REQ-WHD-003 and REQ-WHD-005):
 * whether dossiq is there to take them, and what the hand-over left.
 */

/**
 * Where an administrator installs dossiq.
 */
export const DOSSIQ_APP_STORE_URL = 'https://apps.nextcloud.com/apps/dossiq'

/**
 * Whether the settings must say that dossiq, which handles the Woo requests, is not installed.
 *
 * @param {object|null} state The hand-over state from the server.
 * @return {boolean} True when dossiq is missing.
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */
export function dossiqMissing(state) {
	return !!state && state.dossiqInstalled === false
}

/**
 * The sentences about the hand-over: how many requests are not moved yet,
 * how many dossiq could not move, when that was counted, and whether the
 * stored requests are read-only.
 *
 * @param {{unmigrated: ?number, failed: ?number, checkedAt: string, readOnly: boolean, dossiqInstalled: boolean}|null} state The hand-over state, null when it could not be read.
 * @param {function(string, string, object=): string} t The translate function.
 * @param {function(string): string} formatDate Formats the moment.
 * @return {Array<string>} The sentences, in reading order.
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-every-stored-request-is-migrated-with-its-term-and-opencatalogis-timer-is-stopped-after-req-whd-003
 */
export function wooRequestHandoverLines(state, t, formatDate) {
	if (!state || state.dossiqInstalled === false) {
		return []
	}
	if (state.unmigrated === null || state.unmigrated === undefined) {
		return [t('opencatalogi', 'The Woo requests stored here have not been handed to dossiq yet.')]
	}
	const lines = [
		t('opencatalogi', 'Woo requests not yet moved to dossiq: {count}', { count: state.unmigrated }),
		t('opencatalogi', 'Of those, dossiq could not move: {count}', { count: state.failed ?? 0 }),
	]
	if (state.checkedAt) {
		lines.push(t('opencatalogi', 'Counted on {date}', { date: formatDate(state.checkedAt) }))
	}
	if (state.readOnly === true) {
		lines.push(t('opencatalogi', 'Every Woo request has moved to dossiq; the requests stored here are read-only.'))
	}
	return lines
}
