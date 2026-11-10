/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { isBlankValue } from './objectMetadata.js'

/**
 * How the object modal turns its form into a save payload and checks its
 * fields: the cleaned form values, the schema defaults, the payload itself,
 * the per-property validation errors and the publish-action guard. Pure
 * functions over the object, the schema properties and the form.
 */

/**
 * Whether a form key is a real property name: a non-empty string that is
 * not an array index and not a Vue or internal key.
 *
 * @param {string} key - The key
 * @return {boolean} True when it is a property name
 */
function isPropertyKey(key) {
	return (
		typeof key === 'string'
		&& key.length > 0
		&& !/^\d+$/.test(key)
		&& !key.startsWith('_')
		&& !key.startsWith('$')
	)
}

/**
 * The form values under real property names; an array form gives none.
 *
 * @param {object|Array|null} formData - The form data
 * @return {object} The cleaned values
 */
export function cleanFormValues(formData) {
	// If formData is somehow an array, convert it properly
	if (Array.isArray(formData)) {
		// This should never happen, but just in case
		return {}
	}

	const cleaned = {}
	for (const [key, value] of Object.entries(formData || {})) {
		if (isPropertyKey(key)) {
			cleaned[key] = value
		}
	}
	return cleaned
}

/** Fill values for a missing property after an API result, per schema type. */
const FILL_VALUES = new Map([
	['number', () => 0],
	['integer', () => 0],
	['boolean', () => false],
	['array', () => []],
	['object', () => ({})],
])

/**
 * The fill value of a missing schema property after an API result: the
 * const or the empty string for a string, the type's empty value otherwise.
 *
 * @param {object} schemaProperty - The schema property
 * @return {*} The fill value
 */
function schemaFillValue(schemaProperty) {
	if (schemaProperty.type === 'string') {
		return schemaProperty.const || ''
	}
	const fill = FILL_VALUES.get(schemaProperty.type)
	return fill ? fill() : ''
}

/**
 * Add every schema property an object lacks, with its fill value.
 *
 * @param {object} mergedObject - The object, changed in place
 * @param {object} schemaProperties - The schema properties
 * @return {object} The object
 */
export function withSchemaFillValues(mergedObject, schemaProperties) {
	for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
		if (!Object.hasOwn(mergedObject, key)) {
			mergedObject[key] = schemaFillValue(schemaProperty)
		}
	}
	return mergedObject
}

/**
 * An edited value of an array property as an array: a comma-separated
 * string split, null kept, false as empty, anything else wrapped as text.
 *
 * @param {*} value - The edited value
 * @return {Array|null} The array
 */
function normalizeArrayValue(value) {
	if (Array.isArray(value)) return value
	if (typeof value === 'string') return value.split(/ *, */g).filter(Boolean)
	if (value === null) return null
	// Edge case: some inputs may pass boolean false; treat as empty array
	if (value === false) return []
	return [String(value)].filter(Boolean)
}

/**
 * The payload value of a schema property that neither the form nor the
 * object holds: a string's const, else null so the backend sets defaults.
 *
 * @param {object} schemaProperty - The schema property
 * @return {*} The value
 */
function missingPropertyValue(schemaProperty) {
	return schemaProperty.type === 'string' ? schemaProperty.const || null : null
}

/**
 * Put an edited schema property into the payload: removed when the form
 * marked it for deletion (undefined), normalised when it is an array.
 *
 * @param {object} objectData - The payload, changed in place
 * @param {{key: string, schemaProperty: object, form: object}} entry - The property and the cleaned form values
 * @return {void}
 */
function applyEditedProperty(objectData, { key, schemaProperty, form }) {
	const value = form[key]
	if (value === undefined) {
		// Let the backend handle defaults: leave it out of the payload
		delete objectData[key]
		return
	}
	objectData[key] = schemaProperty.type === 'array' ? normalizeArrayValue(value) : value
}

/**
 * Put one schema property into the payload: the edited value, else the
 * object's value, else the value for a missing property.
 *
 * @param {object} objectData - The payload, changed in place
 * @param {{key: string, schemaProperty: object, form: object, current: object}} entry - The property, the cleaned form values and the current object
 * @return {void}
 */
function applySchemaProperty(objectData, entry) {
	const { key, schemaProperty, form, current } = entry
	if (Object.hasOwn(form, key)) {
		applyEditedProperty(objectData, entry)
	} else if (Object.hasOwn(current, key)) {
		objectData[key] = current[key]
	} else {
		objectData[key] = missingPropertyValue(schemaProperty)
	}
}

/**
 * Whether an edited key outside the schema belongs in the payload: a
 * non-empty, non-numeric name that the schema does not define.
 *
 * @param {string} key - The key
 * @param {object} schemaProperties - The schema properties
 * @return {boolean} True when it belongs in the payload
 */
function isExtraFormKey(key, schemaProperties) {
	return (
		!Object.hasOwn(schemaProperties, key)
		&& typeof key === 'string'
		&& key.length > 0
		&& !/^\d+$/.test(key)
	)
}

/**
 * The object's own values except its id.
 *
 * @param {object} currentObjectData - The current object
 * @return {object} The values
 */
function withoutId(currentObjectData) {
	const objectData = {}
	for (const [key, value] of Object.entries(currentObjectData)) {
		if (key !== 'id') {
			objectData[key] = value
		}
	}
	return objectData
}

/**
 * The complete save payload of an object: its current values without the
 * id, every schema property, and the edited properties outside the schema
 * that are not marked for deletion.
 *
 * @param {object|null} currentObject - The current object
 * @param {{schemaProperties: object, form: object}} input - The schema properties and the cleaned form values
 * @return {object} The payload
 */
export function buildObjectPayload(currentObject, { schemaProperties, form }) {
	const current = currentObject || {}
	const objectData = withoutId(current)

	for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
		applySchemaProperty(objectData, { key, schemaProperty, form, current })
	}

	for (const [key, value] of Object.entries(form)) {
		if (isExtraFormKey(key, schemaProperties) && value !== undefined) {
			objectData[key] = value
		}
	}

	return objectData
}

/**
 * Whether a value leaves nothing to clear: blank, or an empty array or
 * object.
 *
 * @param {*} value - The value
 * @return {boolean} True when empty
 */
export function isEmptyValue(value) {
	return (
		isBlankValue(value)
		|| (typeof value === 'object' && Object.keys(value).length === 0)
	)
}

/**
 * Whether a schema property is validated on the form: present, shown, and
 * not the metadata or id key.
 *
 * @param {string} key - The property key
 * @param {object|null} prop - The schema property
 * @return {boolean} True when it is validated
 */
function isValidatedProperty(key, prop) {
	return Boolean(prop) && prop.hideOnForm !== true && key !== '@self' && key !== 'id'
}

/**
 * One field error, labelled with the property's title or key.
 *
 * @param {string} key - The property key
 * @param {{prop: object, error: string}} failure - The schema property and its error
 * @return {{key: string, label: string, error: string}} The entry
 */
function fieldErrorEntry(key, { prop, error }) {
	return { key, label: prop.title || key, error }
}

/**
 * The validation errors of the filled, validated properties.
 *
 * @param {object} properties - The schema properties
 * @param {{valueOf: Function, validate: Function}} check - Reads a property's value and validates a value against its property
 * @return {Array<{key: string, label: string, error: string}>} The errors
 */
export function propertyErrors(properties, { valueOf, validate }) {
	const errors = []
	for (const [key, prop] of Object.entries(properties)) {
		if (!isValidatedProperty(key, prop)) continue
		const value = valueOf(key)
		if (isBlankValue(value)) continue
		const error = validate(value, prop)
		if (error) errors.push(fieldErrorEntry(key, { prop, error }))
	}
	return errors
}

/**
 * A date value as a Date, or null when there is none.
 *
 * @param {string|null|undefined} value - The date
 * @return {Date|null} The date
 */
function optionalDate(value) {
	return value ? new Date(value) : null
}

/**
 * Whether an object offers the publish action: it is depublished by now,
 * never published, or scheduled but not live yet.
 *
 * @param {object|null} object - The object
 * @param {Date} now - The current time
 * @return {boolean} True when the action shows
 */
export function offersPublishAction(object, now) {
	if (!object) return false
	const published = optionalDate(object.publicationDate)
	const depublished = optionalDate(object.depublicationDate)

	if (depublished) return depublished < now // currently depublished
	return !published || published > now // never published, or scheduled but not yet live
}
