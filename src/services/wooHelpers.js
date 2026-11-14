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

/**
 * The assessments that need at least one refusal ground before they are saved.
 *
 * @type {Array<string>}
 */
export const NEEDS_GROUNDS = ['deels_openbaar', 'niet_openbaar']

/**
 * Whether moving a document into this assessment first asks for refusal grounds.
 *
 * @param {string} assessment The target assessment.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
 * @return {boolean} True for partly public and not public.
 */
export function needsGrounds(assessment) {
	return NEEDS_GROUNDS.includes(assessment)
}

/**
 * Group a batch's assessments into the four queue columns, in their order.
 *
 * An unknown or missing assessment counts as still to assess.
 *
 * @param {Array<object>} assessments The assessment objects.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
 * @return {object} One array per assessment key.
 */
export function groupByAssessment(assessments) {
	const columns = ASSESSMENTS.reduce((acc, key) => ({ ...acc, [key]: [] }), {})
	for (const item of assessments || []) {
		const key = ASSESSMENTS.includes(item?.assessment)
			? item.assessment
			: 'te_beoordelen'
		columns[key].push(item)
	}
	return columns
}

/**
 * Replace one assessment in a list by id, keeping the others and their order.
 *
 * @param {Array<object>} assessments The assessments.
 * @param {object} updated The saved assessment.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
 * @return {Array<object>} The new list.
 */
export function replaceAssessment(assessments, updated) {
	return (assessments || []).map((item) =>
		item.id === updated.id ? { ...item, ...updated } : item,
	)
}

/**
 * The redaction state of a partly public card, as a key the page labels:
 * `failed`, `unchecked`, `checked` or `pending`. Null for any other card.
 *
 * @param {object} item The assessment.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004
 * @return {string|null} The state key.
 */
export function redactionState(item) {
	if (item?.assessment !== 'deels_openbaar') {
		return null
	}
	if (item.redactionStatus === 'failed') {
		return 'failed'
	}
	if (item.redactionStatus !== 'verified') {
		return 'pending'
	}
	return item.redactionVerifiedBy ? 'checked' : 'unchecked'
}

/**
 * Format a byte count for a card's meta line.
 *
 * @param {number|string|null|undefined} bytes The size in bytes.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
 * @return {string} The size, or an empty string when unknown.
 */
export function formatSize(bytes) {
	const size = Number(bytes)
	if (!Number.isFinite(size) || size <= 0) {
		return ''
	}
	const units = ['B', 'KB', 'MB', 'GB']
	let value = size
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

/**
 * The findings of a redacted file as rows for the redaction page, in the
 * order they appear, with the counts of kept and rejected findings.
 *
 * Kept is a finding that is redacted, rejected one a person chose to skip. A
 * finding whose decision the caller may not see (OpenRegister masks it to
 * `null`) is listed but counted as neither.
 *
 * @param {Array<object>} relations The entity relations OpenRegister answers for the file.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
 * @return {{rows: Array<object>, kept: number, rejected: number}} The rows and counts.
 */
export function redactionRows(relations) {
	const sorted = [...(relations || [])].sort(
		(a, b) =>
			(Number(a?.page) || 0) - (Number(b?.page) || 0)
			|| (Number(a?.positionStart) || 0) - (Number(b?.positionStart) || 0),
	)
	const rows = sorted.map((relation, index) => ({
		number: index + 1,
		id: relation?.id ?? null,
		entityId: relation?.entityId ?? null,
		what: String(relation?.anonymizedValue || relation?.role || ''),
		page: relation?.page ?? null,
		grounds: Array.isArray(relation?.bases) ? relation.bases : [],
		state:
			relation?.skipAnonymization === true
				? 'rejected'
				: relation?.skipAnonymization === false
					? 'kept'
					: 'hidden',
	}))
	return {
		rows,
		kept: rows.filter((row) => row.state === 'kept').length,
		rejected: rows.filter((row) => row.state === 'rejected').length,
	}
}

/**
 * The three steps of a partly public document's redaction, each done or not:
 * the original kept, the redacted version generated, and verified by a person.
 *
 * @param {object} item The assessment.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
 * @return {Array<{key: string, done: boolean, by: string, at: string}>} The steps, in order.
 */
export function redactionSteps(item) {
	const generated =
		item?.redactionStatus === 'verified' && Boolean(item?.anonymizedDocument)
	return [
		{
			key: 'original',
			done: Boolean(item?.documentReference),
			by: '',
			at: '',
		},
		{
			key: 'generated',
			done: generated,
			by: '',
			at: generated ? String(item?.assessedAt || '') : '',
		},
		{
			key: 'verified',
			done: Boolean(item?.redactionVerifiedBy),
			by: String(item?.redactionVerifiedBy || ''),
			at: String(item?.redactionVerifiedAt || ''),
		},
	]
}
