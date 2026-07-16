/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure client-side helpers for the WOO transparency surfaces (woo-transparency).
 * Extracted from the Vue components so the WOO-specific client logic — redaction
 * instruction building (entity -> weigeringsgrond mapping), pages-with-entities
 * derivation, and progress/summary derivation — is unit-testable offline without
 * a DOM. The queue/board mechanics themselves are the OpenRegister deck leaf's
 * concern (ADR-022), not here.
 */

/**
 * The canonical assessment vocabulary (mirrors WooService::ASSESSMENTS).
 *
 * @type {Array<string>}
 */
export const ASSESSMENTS = ['te_beoordelen', 'openbaar', 'deels_openbaar', 'niet_openbaar']

/**
 * Build the redaction-instruction payload from the per-entity selection + grounds.
 *
 * @param {Array<object>} entities The detected entities: [{ id, text, page }].
 * @param {object} selected Map of entityId -> boolean (marked for redaction).
 * @param {object} grounds Map of entityId -> { id } (selected weigeringsgrond).
 * @spec openspec/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context
 * @return {Array<object>} The redaction instructions.
 */
export function buildRedactionInstructions(entities, selected, grounds) {
	return (entities || [])
		.filter((e) => selected && selected[e.id])
		.map((e) => ({
			entityId: e.id,
			text: e.text,
			page: e.page,
			weigeringsgrond: (grounds && grounds[e.id] && grounds[e.id].id) || null,
		}))
}

/**
 * Derive the sorted, unique list of pages that carry detected entities.
 *
 * @param {Array<object>} entities The detected entities: [{ page }].
 * @spec openspec/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context
 * @return {Array<number>} The ascending page numbers.
 */
export function pagesWithEntities(entities) {
	return [...new Set((entities || []).map((e) => e.page).filter(Boolean))].sort((a, b) => a - b)
}

/**
 * Derive a per-status document summary from a list of assessment objects.
 *
 * @param {Array<object>} assessments The assessment objects: [{ assessment }].
 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
 * @return {object} { counts, total, assessed, progressLabel }.
 */
export function deriveSummary(assessments) {
	const counts = ASSESSMENTS.reduce((acc, key) => ({ ...acc, [key]: 0 }), {})
	for (const a of (assessments || [])) {
		const status = a.assessment || 'te_beoordelen'
		if (Object.prototype.hasOwnProperty.call(counts, status)) {
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
