/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the publish wrote into each published file: the outcome
 * `EmbeddedTitleWriter` stored per file name on the batch's `wooPublication`
 * (`embeddedTitles`), as the sentence the officer reads
 * (published-file-carries-its-facts REQ-PFF-002).
 *
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.3
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The sentence for one outcome: written, or not written with the reason.
 *
 * @param {object|null} outcome `{status, reason?}` as the writer answered it.
 * @return {string} The sentence.
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.3
 */
export function embeddedTitleLabel(outcome) {
	const status = outcome && typeof outcome === 'object' ? String(outcome.status || '') : ''
	if (status === 'written') {
		return t('opencatalogi', 'Title written into the file')
	}
	const reasons = {
		signed: t('opencatalogi', 'Title not written: the file is signed'),
		encrypted: t('opencatalogi', 'Title not written: the file is encrypted'),
		unparseable: t('opencatalogi', 'Title not written: the file could not be read'),
		'unsupported-type': t('opencatalogi', 'Title not written: this file type has no title'),
	}
	const reason = outcome && typeof outcome === 'object' ? String(outcome.reason || '') : ''

	return reasons[reason] ?? t('opencatalogi', 'Title not written')
}

/**
 * One row per published file, by name, with its sentence and whether the
 * title was written; empty when the publish stored no outcomes.
 *
 * @param {object|null} wooPublication The batch's `wooPublication`.
 * @return {Array<{name: string, label: string, written: boolean}>} The rows, by name.
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.3
 */
export function embeddedTitleRows(wooPublication) {
	const outcomes = wooPublication && typeof wooPublication.embeddedTitles === 'object' && !Array.isArray(wooPublication.embeddedTitles)
		? wooPublication.embeddedTitles
		: {}

	return Object.keys(outcomes)
		.sort((a, b) => a.localeCompare(b))
		.map((name) => ({
			name,
			label: embeddedTitleLabel(outcomes[name]),
			written: Boolean(outcomes[name]) && outcomes[name].status === 'written',
		}))
}
