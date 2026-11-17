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
	const items = relationItems(property)
	return items !== null && hasRelationType(items) && schemaSlug !== '' && refTarget(items.$ref) === schemaSlug
}

/**
 * Whether a value is a non-null object.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for an object.
 */
function isObject(value) {
	return value !== null && typeof value === 'object'
}

/**
 * The items of an array property, or null for any other property.
 *
 * @param {object} property The schema property.
 * @return {object|null} The items.
 */
function relationItems(property) {
	return property?.type === 'array' && isObject(property.items) ? property.items : null
}

/**
 * Whether array items carry an `x-openregister-relation` with a type.
 *
 * @param {object} items The array items.
 * @return {boolean} True when the items are a typed relation.
 */
function hasRelationType(items) {
	const relation = items['x-openregister-relation']
	return isObject(relation) && typeof relation.type === 'string' && relation.type !== ''
}

/**
 * Whether an id is missing: null, undefined or the empty string.
 *
 * @param {string|number|null|undefined} value The id.
 * @return {boolean} True when there is no id.
 */
function isBlank(value) {
	return value === null || value === undefined || value === ''
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
	if (isBlank(register) || isBlank(schema?.id)) {
		return {}
	}

	const slug = slugOf(schema)
	const keys = Object.keys(schema.properties || {}).filter((key) => isSelfRelationProperty(schema.properties[key], slug))
	return Object.fromEntries(keys.map((key) => [key, pickerOverride(register, schema.id)]))
}

/**
 * The slug of a schema, or '' when it has none.
 *
 * @param {object} schema The schema.
 * @return {string} The slug.
 */
function slugOf(schema) {
	return typeof schema.slug === 'string' ? schema.slug : ''
}

/**
 * The override that edits a relation property as a multi-select over one register and schema.
 *
 * @param {string|number} register The register id.
 * @param {string|number} schemaId The schema id.
 * @return {object} The override.
 */
function pickerOverride(register, schemaId) {
	return {
		widget: 'select',
		selectMultiple: true,
		optionsFrom: {
			register: String(register),
			schema: String(schemaId),
			labelField: 'title',
		},
	}
}
