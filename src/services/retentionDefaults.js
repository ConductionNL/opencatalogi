/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * retentionDefaults: one catalogue's retention defaults per Woo information
 * category, read from and written back into the instance-wide defaults map
 * `GET`/`POST /api/retention/defaults` serves (RET-004).
 *
 * The map is `{ <catalogSlug>: { <category code>: { termMonths, action },
 * _fallback: { termMonths, action } } }`. The catalogue-wide fallback is the
 * row `_fallback`.
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */

/** The row key of the catalogue-wide fallback. */
export const FALLBACK = '_fallback'

/** The actions a retention default may take when the term ends (RetentionService::ACTIONS). */
export const RETENTION_ACTIONS = Object.freeze(['review', 'depublish', 'archive'])

/**
 * A stored term in months as the field shows it: a positive whole number, else empty.
 *
 * @param {unknown} value - the stored term.
 * @return {string}
 */
function termText(value) {
	return Number.isInteger(value) && value > 0 ? String(value) : ''
}

/**
 * The rows the section edits: the fallback first, then each category in the given order.
 *
 * @param {object} defaults - the instance-wide defaults map.
 * @param {string} slug - the catalogue slug.
 * @param {Array<{code: string, label: string}>} categories - the Woo information categories.
 * @return {Array<{code: string, label: string, termMonths: string, action: string}>}
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */
export function catalogRows(defaults, slug, categories) {
	const own = defaults && typeof defaults[slug] === 'object' && defaults[slug] !== null ? defaults[slug] : {}
	const row = (code, label) => {
		const rule = own[code] && typeof own[code] === 'object' ? own[code] : {}
		return {
			code,
			label,
			termMonths: termText(rule.termMonths),
			action: RETENTION_ACTIONS.includes(rule.action) ? rule.action : '',
		}
	}
	return [{ code: FALLBACK, label: '' }, ...categories].map((category) => row(category.code, category.label))
}

/**
 * Whether a row's term is empty or a positive whole number of months.
 *
 * @param {{termMonths: string}} row - the row.
 * @return {boolean}
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */
export function termIsValid(row) {
	const text = String(row.termMonths ?? '').trim()
	return text === '' || /^[1-9]\d*$/.test(text)
}

/**
 * The defaults map with this catalogue's entry replaced by the rows.
 *
 * A row with neither a term nor an action is left out; a catalogue with no rows
 * left loses its entry. Other catalogues' entries are kept as they are.
 *
 * @param {object} defaults - the instance-wide defaults map.
 * @param {string} slug - the catalogue slug.
 * @param {Array<{code: string, termMonths: string, action: string}>} rows - the edited rows.
 * @return {object} a new map.
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */
export function withCatalogRows(defaults, slug, rows) {
	const entry = {}
	for (const row of rows) {
		const text = String(row.termMonths ?? '').trim()
		const rule = {}
		if (text !== '') {
			rule.termMonths = Number(text)
		}
		if (RETENTION_ACTIONS.includes(row.action)) {
			rule.action = row.action
		}
		if (Object.keys(rule).length > 0) {
			entry[row.code] = rule
		}
	}
	const next = { ...(defaults && typeof defaults === 'object' ? defaults : {}) }
	delete next[slug]
	return Object.keys(entry).length > 0 ? { ...next, [slug]: entry } : next
}
