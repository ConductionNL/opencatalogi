/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the metadata suggestions widget shows and asks: the pending
 * suggestions, the endpoints, the words for a refusal, and the fields a rule
 * filled when the publication was created (woo-metadata-suggestions).
 */

/**
 * The publication fields a suggestion can fill, with the label an editor reads.
 *
 * @param {function(string, string): string} t The translate function.
 * @return {Object<string, string>} Field to label.
 */
export function fieldLabels(t) {
	return {
		wooCategory: t('opencatalogi', 'Woo information category'),
		soortHandeling: t('opencatalogi', 'Document handling'),
		organization: t('opencatalogi', 'Organisation'),
		title: t('opencatalogi', 'Title'),
		summary: t('opencatalogi', 'Summary'),
	}
}

/**
 * The suggestions still waiting for a person, in the order the server gave them.
 *
 * @param {Array<object>} suggestions Every suggestion on the publication.
 * @return {Array<object>} The pending ones.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
export function pendingSuggestions(suggestions) {
	return (Array.isArray(suggestions) ? suggestions : []).filter(
		(suggestion) => suggestion && suggestion.status === 'pending',
	)
}

/**
 * The path of a publication's suggestions, relative to the app.
 *
 * @param {string} publicationId The publication id.
 * @return {string} The path with the id encoded.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */
export function suggestionsPath(publicationId) {
	return (
		'/apps/opencatalogi/api/publications/'
		+ encodeURIComponent(publicationId)
		+ '/metadata-suggestions'
	)
}

/**
 * The path of a decision on one suggestion, relative to the app.
 *
 * @param {string} suggestionId The suggestion id.
 * @param {'accept'|'reject'} decision The decision.
 * @return {string} The path with the id encoded.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
export function decisionPath(suggestionId, decision) {
	if (decision !== 'accept' && decision !== 'reject') {
		throw new Error('A suggestion is accepted or rejected, not ' + decision)
	}
	return (
		'/apps/opencatalogi/api/metadata-suggestions/'
		+ encodeURIComponent(suggestionId)
		+ '/'
		+ decision
	)
}

/**
 * Who made a suggestion, as an editor reads it.
 *
 * @param {string} source `rules` or `hermiq`.
 * @param {function(string, string): string} t The translate function.
 * @return {string} The label.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */
export function sourceLabel(source, t) {
	if (source === 'hermiq') {
		return t('opencatalogi', 'Suggested by Hermiq')
	}
	return t('opencatalogi', 'Suggested by the rules')
}

/**
 * Why a request failed, in the server's words when it gave any.
 *
 * @param {object} error The axios error.
 * @param {function(string, string): string} t The translate function.
 * @return {string} The message.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
export function refusalMessage(error, t) {
	const data = error && error.response && error.response.data
	if (data && typeof data.message === 'string' && data.message !== '') {
		return data.message
	}
	return t('opencatalogi', 'That did not work. Try again.')
}

/**
 * The fields a rule filled when the publication was created, each with its label
 * and value, for the "filled automatically" note (REQ-WMS-004).
 *
 * @param {object} publication The publication as the page holds it.
 * @param {function(string, string): string} t The translate function.
 * @return {Array<{field: string, label: string, value: string}>} The filled fields.
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */
export function filledByRule(publication, t) {
	const records =
		publication && Array.isArray(publication.filledByRule)
			? publication.filledByRule
			: []
	const labels = fieldLabels(t)
	return records
		.filter((record) => record && labels[record.field])
		.map((record) => ({
			field: record.field,
			label: labels[record.field],
			value: String(record.value ?? ''),
		}))
}
