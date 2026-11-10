/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * How the admin settings page reads the schemas a register lists: as schema
 * objects or as bare ids. Pure functions.
 */

/**
 * Whether a listed schema is a schema object with an id and a title.
 *
 * @param {*} schema - The listed schema
 * @return {boolean} True when it is a schema object
 */
export function isSchemaObject(schema) {
	return Boolean(schema && typeof schema === 'object' && schema.id && schema.title)
}

/**
 * Whether a register lists schema objects rather than only ids.
 *
 * @param {Array|undefined} schemas - The register's schemas
 * @return {boolean} True when it lists at least one schema object
 */
export function hasSchemaObjects(schemas) {
	return Array.isArray(schemas) && schemas.length > 0 && schemas.some(isSchemaObject)
}

/**
 * The fallback schema of a configured id: the id with a generic title.
 *
 * @param {string} schemaId - The configured schema id
 * @return {{id: string, title: string}} The schema
 */
function fallbackSchema(schemaId) {
	return {
		id: schemaId,
		title: `Schema ${schemaId}`, // Fallback title.
	}
}

/**
 * The listed schema of a configured id: the fallback when the register
 * lists the id as a number, the matching schema object otherwise.
 *
 * @param {Array} schemas - The register's schemas
 * @param {string} schemaId - The configured schema id
 * @return {object|null|undefined} The schema, when listed
 */
function listedSchema(schemas, schemaId) {
	// Handle both schema IDs (numbers) and schema objects.
	if (schemas.some((s) => typeof s === 'number')) {
		// Schemas are just IDs, check if our schemaId is in the array.
		return schemas.includes(parseInt(schemaId)) ? fallbackSchema(schemaId) : null
	}
	// Schemas are objects, find the matching one.
	return schemas.filter(isSchemaObject).find((s) => s.id.toString() === schemaId)
}

/**
 * The schema to show for a configured id. A schema the register does not
 * list (an orphaned schema, see SettingsService::reconcileRegisterSchemaLinks)
 * is surfaced anyway: leaving the select empty makes saveAll() write an empty
 * string back and silently destroy a working configuration.
 *
 * @param {Array} schemas - The register's schemas
 * @param {string} schemaId - The configured schema id
 * @return {{id: string|number, title: string}} The schema
 */
export function configuredSchema(schemas, schemaId) {
	return listedSchema(schemas, schemaId) || fallbackSchema(schemaId)
}

/**
 * The listed schema object whose title is the object type's name, ignoring
 * case.
 *
 * @param {Array} schemas - The register's schemas
 * @param {string} type - The object type
 * @return {object|undefined} The schema
 */
export function schemaNamedLike(schemas, type) {
	return schemas
		.filter(isSchemaObject)
		.find((schema) => schema.title.toLowerCase() === type.toLowerCase())
}
