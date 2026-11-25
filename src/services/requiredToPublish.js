/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fields a publication needs before it can be published, read from its
 * schema's lifecycle (`x-openregister-lifecycle.states.published.fields.required`),
 * never from a list kept here. OpenRegister stays the authority: its 422 on
 * the transition is what refuses a publish; this only tells the editor first.
 *
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
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
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
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
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
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
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
 */
export function refusalMessage(error) {
	const data = error?.response?.data
	return String(data?.message ?? data?.error ?? error?.message ?? '')
}

/**
 * The body to save, without a not-supplied entry for a field the editor has now filled in.
 *
 * The upgrade records the required fields a legacy publication left empty in
 * OpenRegister's `@notSupplied` (design D2). OpenRegister refuses a field that
 * is both filled in and recorded as not supplied, so an editor completing such
 * a publication would be refused without this. Entries for fields still empty
 * stay, and the key goes when no entry is left. The body passed in is not changed.
 *
 * @param {object} body The publication body about to be saved.
 * @return {object} The body to send.
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/design.md#d2-existing-publications
 */
export function clearAnsweredGaps(body) {
	const record = body?.['@notSupplied']
	if (!record || typeof record !== 'object' || Array.isArray(record)) return body

	const kept = Object.fromEntries(
		Object.entries(record).filter(([field]) => isEmpty(body[field])),
	)
	const rest = { ...body }
	delete rest['@notSupplied']
	return Object.keys(kept).length === 0 ? rest : { ...rest, '@notSupplied': kept }
}
