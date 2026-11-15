/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Form fields for relation properties: an array property whose items carry
 * `x-openregister-relation` and point (`$ref`) at the object's own schema is
 * edited as a multi-select over the objects of that same register and schema,
 * labelled by title, instead of as free text holding ids.
 *
 * @module modals/object/relationFields
 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-1.4
 */

/**
 * The last path segment of a `$ref`: `#/components/schemas/publication` and
 * `publication` both give `publication`.
 *
 * @param {unknown} ref The `$ref`.
 * @return {string} The segment, or '' for anything that is not a string.
 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-1.4
 */
export function refTarget(ref) {
	if (typeof ref !== 'string' || ref.trim() === '') {
		return ''
	}
	const parts = ref.trim().split('/')
	return parts[parts.length - 1]
}

/**
 * Whether a schema property is a relation to an object of the given schema.
 *
 * @param {object} property The schema property.
 * @param {string} schemaSlug The slug of the object's own schema.
 * @return {boolean} True for an array whose items are a relation to that schema.
 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-1.4
 */
export function isSelfRelationProperty(property, schemaSlug) {
	if (!property || property.type !== 'array' || !property.items || typeof property.items !== 'object') {
		return false
	}
	const relation = property.items['x-openregister-relation']
	if (!relation || typeof relation !== 'object' || typeof relation.type !== 'string' || relation.type === '') {
		return false
	}
	return schemaSlug !== '' && refTarget(property.items.$ref) === schemaSlug
}

/**
 * The property overrides that make each relation property a picker.
 *
 * @param {object|null} schema The object's schema (`slug`, `properties`).
 * @param {string|number|null} register The object's register id.
 * @return {Record<string, object>} Overrides keyed by property, empty when the register or schema is unknown.
 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-1.4
 */
export function relationFieldOverrides(schema, register) {
	const schemaId = schema?.id
	const slug = typeof schema?.slug === 'string' ? schema.slug : ''
	if (register === null || register === undefined || register === '' || schemaId === null || schemaId === undefined || schemaId === '') {
		return {}
	}

	const overrides = {}
	for (const [key, property] of Object.entries(schema?.properties || {})) {
		if (!isSelfRelationProperty(property, slug)) {
			continue
		}
		overrides[key] = {
			widget: 'select',
			selectMultiple: true,
			optionsFrom: {
				register: String(register),
				schema: String(schemaId),
				labelField: 'title',
			},
		}
	}
	return overrides
}
