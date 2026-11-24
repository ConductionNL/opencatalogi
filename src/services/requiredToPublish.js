/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fields a publication needs before it can be published, read from its
 * schema's lifecycle (`x-openregister-lifecycle.states.published.fields.required`),
 * never from a list kept here. OpenRegister stays the authority: its 422 on
 * the transition is what refuses a publish; this only tells the editor first.
 *
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
 */
import { resolveSchema } from './schemaHelpers.js'

/**
 * The lifecycle block of a schema, top level or under its configuration.
 *
 * @param {object|null} schema The schema.
 * @return {object|null} The lifecycle.
 */
function lifecycleOf(schema) {
	if (!schema || typeof schema !== 'object') return null
	return (
		schema['x-openregister-lifecycle']
		?? schema.configuration?.['x-openregister-lifecycle']
		?? null
	)
}

/**
 * The field names the schema requires in state `published`, in declared order, without duplicates.
 *
 * @param {object|null} schema The schema.
 * @return {string[]} The field names; empty when the schema declares none.
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
 */
export function requiredToPublishFields(schema) {
	const rules = lifecycleOf(schema)?.states?.published?.fields?.required
	if (!Array.isArray(rules)) return []

	const names = []
	for (const rule of rules) {
		for (const field of Array.isArray(rule?.fields) ? rule.fields : []) {
			if (
				typeof field === 'string'
				&& field !== ''
				&& !names.includes(field)
			) {
				names.push(field)
			}
		}
	}
	return names
}

/**
 * Whether a value counts as not filled in: absent, null, a blank string, an empty list or object.
 *
 * @param {unknown} value The value.
 * @return {boolean} True when empty.
 */
function isEmpty(value) {
	if (value === null || value === undefined) return true
	if (typeof value === 'string') return value.trim() === ''
	if (Array.isArray(value)) return value.length === 0
	if (typeof value === 'object') return Object.keys(value).length === 0
	return false
}

/**
 * The fields required to publish that this publication leaves empty.
 *
 * @param {object} publication The publication.
 * @param {object|null} [schema] The schema; resolved from the publication when omitted.
 * @return {string[]} The missing field names.
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
 */
export function missingToPublish(publication, schema = undefined) {
	const fields = requiredToPublishFields(
		schema === undefined ? resolveSchema(publication) : schema,
	)
	return fields.filter((field) => isEmpty(publication?.[field]))
}

/**
 * The message of a refused publish: OpenRegister's 422 text when it sent one, else the error's own.
 *
 * @param {unknown} error The rejected request's error.
 * @return {string} The message.
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
 */
export function refusalMessage(error) {
	const data = error?.response?.data
	return String(data?.message ?? data?.error ?? error?.message ?? '')
}
