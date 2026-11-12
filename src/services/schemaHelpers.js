/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { objectStore } from '../store/store.js'

/**
 * The id a schema reference names: an object's id, uuid or slug, or the
 * reference itself when it is a plain value.
 *
 * @param {object|string|number} ref The schema reference.
 * @return {string|number|null|undefined} The id.
 */
function schemaRefId(ref) {
	return typeof ref === 'object' ? (ref.id ?? ref.uuid ?? ref.slug) : ref
}

/**
 * Whether a schema reference is the schema itself, with its properties.
 *
 * @param {object|string|number} ref The schema reference.
 * @return {boolean}
 */
function isInlineSchema(ref) {
	return typeof ref === 'object' && Boolean(ref.properties)
}

/**
 * The first known schema whose id, uuid or slug equals the id, or null.
 *
 * @param {string|number} id The id to look up.
 * @return {object|null} The schema.
 */
function knownSchema(id) {
	const schemas = objectStore?.availableSchemas
	if (!Array.isArray(schemas)) return null

	const key = String(id)
	return (
		schemas.find((s) =>
			[s.id, s.uuid, s.slug].some((value) => String(value) === key),
		) || null
	)
}

/**
 * The schema of a publication: an inline schema with properties, else the
 * known schema its reference (in @self, else on the publication) names.
 *
 * @param {object} publication The publication.
 * @return {object|null} The schema.
 */
function resolveSchema(publication) {
	const ref = publication?.['@self']?.schema ?? publication?.schema
	if (!ref) return null

	if (isInlineSchema(ref)) {
		return ref
	}

	const id = schemaRefId(ref)
	return id == null ? null : knownSchema(id)
}

/**
 * Check if a publication's schema declares both `publicationDate` and
 * `depublicationDate` properties — the two fields the publish/depublish
 * flows write to. Returns false (fail closed) when the schema can't be
 * resolved, so UI gates disable the action rather than silently saving
 * to a schema that doesn't model these fields.
 *
 * @param {object} publication - The publication object.
 * @return {boolean} true when both fields are declared on the schema.
 *
 * @spec openspec/specs/publications/spec.md
 */
export function schemaHasPublicationDateFields(publication) {
	const schema = resolveSchema(publication)
	const props = schema?.properties
	if (!props || typeof props !== 'object') return false
	return Boolean(props.publicationDate) && Boolean(props.depublicationDate)
}
