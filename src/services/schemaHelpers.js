import { objectStore } from '../store/store.js'

function resolveSchema(publication) {
	const ref = publication?.['@self']?.schema ?? publication?.schema
	if (!ref) return null

	if (typeof ref === 'object' && ref.properties) {
		return ref
	}

	const id = typeof ref === 'object' ? (ref.id ?? ref.uuid ?? ref.slug) : ref
	if (id == null) return null

	const schemas = objectStore?.availableSchemas
	if (!Array.isArray(schemas)) return null

	return schemas.find(s =>
		String(s.id) === String(id)
		|| String(s.uuid) === String(id)
		|| String(s.slug) === String(id),
	) || null
}

/**
 * Check if a publication's schema declares both `publicatiedatum` and
 * `depublicatiedatum` properties — the two fields the publish/depublish
 * flows write to. Returns false (fail closed) when the schema can't be
 * resolved, so UI gates disable the action rather than silently saving
 * to a schema that doesn't model these fields.
 *
 * @param {object} publication - The publication object.
 * @return {boolean} true when both fields are declared on the schema.
 */
export function schemaHasPublicationDateFields(publication) {
	const schema = resolveSchema(publication)
	const props = schema?.properties
	if (!props || typeof props !== 'object') return false
	return Boolean(props.publicatiedatum) && Boolean(props.depublicatiedatum)
}
