/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The frozen marker OpenRegister keeps on a record, as the publication page reads it.
 *
 * A withdrawn publication is frozen (REQ-PWA-003): OpenRegister refuses every data
 * and file write on it until it is unfrozen. The marker sits at `@self.frozen` on an
 * object read, with `by`, `at`, `reason` and `state`; an empty marker or none means
 * the record is not frozen. The same reading as `lib/Service/Publication/FrozenRecord.php`.
 *
 * @param {object|null|undefined} record The publication as the page read it.
 * @return {{by: string, at: string, reason: string, state: string}|null} The marker, or null when the record is not frozen.
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.4
 */
export function frozenMarker(record) {
	if (!record || typeof record !== 'object') {
		return null
	}
	const marker = record['@self']?.frozen ?? record.frozen ?? null
	if (
		!marker
		|| typeof marker !== 'object'
		|| Array.isArray(marker)
		|| Object.keys(marker).length === 0
	) {
		return null
	}
	const text = (key) => (typeof marker[key] === 'string' ? marker[key].trim() : '')
	return {
		by: text('by'),
		at: text('at'),
		reason: text('reason'),
		state: text('state'),
	}
}

/**
 * The page's condition for a frozen record, in the `visibleWhen` grammar the
 * detail page reads as `config.readOnlyWhen`: while it holds, Edit, the edit
 * header actions and the inline editors are off. Kept here so the manifest
 * and its test name one condition.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.4
 */
export const FROZEN_READ_ONLY_WHEN = Object.freeze({
	field: '@self.frozen',
	op: 'notEmpty',
})
