/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure client-side helpers for the WOO transparency surfaces (woo-transparency).
 * Extracted from the Vue components so the WOO-specific client logic, progress and
 * summary derivation and the ready-for-review gate, is unit-testable offline without
 * a DOM. The queue/board mechanics themselves are the OpenRegister deck leaf's
 * concern (ADR-022), not here.
 */

/**
 * The canonical assessment vocabulary (mirrors WooService::ASSESSMENTS).
 *
 * @type {Array<string>}
 */
export const ASSESSMENTS = [
	'te_beoordelen',
	'openbaar',
	'deels_openbaar',
	'niet_openbaar',
]

/**
 * Derive a per-status document summary from a list of assessment objects.
 *
 * @param {Array<object>} assessments The assessment objects: [{ assessment }].
 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
 * @return {object} { counts, total, assessed, progressLabel }.
 */
export function deriveSummary(assessments) {
	const counts = ASSESSMENTS.reduce((acc, key) => ({ ...acc, [key]: 0 }), {})
	for (const a of assessments || []) {
		const status = a.assessment || 'te_beoordelen'
		if (Object.hasOwn(counts, status)) {
			counts[status]++
		}
	}
	const total = ASSESSMENTS.reduce((sum, key) => sum + counts[key], 0)
	const assessed = total - counts.te_beoordelen
	return { counts, total, assessed, progressLabel: `${assessed}/${total}` }
}

/**
 * Whether a batch may move to "ready_for_review": at least one document and none
 * left in "te_beoordelen".
 *
 * @param {object} summary The summary from {@link deriveSummary}.
 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
 * @return {boolean} True when reviewable.
 */
export function canMarkReadyForReview(summary) {
	if (!summary || summary.total <= 0) {
		return false
	}
	return (summary.counts.te_beoordelen || 0) === 0
}
