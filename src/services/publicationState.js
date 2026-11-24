/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one state of a publication as the list and the detail page show it.
 * The server computes the value (`state` per list row, `GET .../visibility`
 * on the page); the browser only labels it, never derives it from dates
 * (publication-lifecycle-on-or REQ-PLC-002).
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-state-of-a-publication-is-one-value-the-server-computes-req-plc-002
 */

import { translate as t } from '@nextcloud/l10n'
import { comingDepublication } from './publicationVisibility.js'

/**
 * The visible label of a server-computed publication state; an unknown value
 * shows as it is, an empty one as nothing.
 *
 * @param {string} value The state: draft, in_review, approved, scheduled, public, withdrawn or archived.
 * @return {string} The label.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-state-of-a-publication-is-one-value-the-server-computes-req-plc-002
 */
export function publicationStateLabel(value) {
	const labels = {
		draft: t('opencatalogi', 'Draft'),
		in_review: t('opencatalogi', 'In review'),
		approved: t('opencatalogi', 'Approved'),
		scheduled: t('opencatalogi', 'Scheduled'),
		public: t('opencatalogi', 'Public'),
		withdrawn: t('opencatalogi', 'Withdrawn'),
		archived: t('opencatalogi', 'Archived'),
	}
	const state = String(value ?? '')

	return labels[state] ?? state
}

/**
 * The state cell of a publications list row: the state's label, and the
 * marker "until <date>" while the row's depublication date lies ahead
 * (publication-schedule-guards REQ-PSG-003).
 *
 * @param {string} value The server-computed state.
 * @param {object|null} [row] The list row, read for its `depublicationDate`.
 * @param {Date} [now] The moment to compare with.
 * @return {string} The cell text.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */
export function publicationStateCell(value, row = null, now = new Date()) {
	const label = publicationStateLabel(value)
	const date = comingDepublication(row, now)
	if (date === null) {
		return label
	}
	const marker = t('opencatalogi', 'until {date}', { date: date.toLocaleDateString() })

	return label === '' ? marker : label + ' · ' + marker
}

/**
 * The cell formatters the app adds to the library's built-in ones.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-state-of-a-publication-is-one-value-the-server-computes-req-plc-002
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */
export const PUBLICATION_FORMATTERS = {
	publicationState: (value, row) => publicationStateCell(value, row),
}
