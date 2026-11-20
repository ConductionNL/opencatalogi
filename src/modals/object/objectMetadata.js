/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * How the object modal describes an object and finds its schema: the
 * metadata rows, the display name and schema name of the title, and the
 * schema an object refers to. Pure functions over the object and the lists
 * the object store holds.
 */

/**
 * A date as a local date-time string, or the fallback when there is none.
 *
 * @param {string|null|undefined} value - The date
 * @param {string} fallback - The text without a date
 * @return {string} The text
 */
export function formatDateOr(value, fallback) {
	return value ? new Date(value).toLocaleString() : fallback
}

/**
 * The label of an embedded register or schema object: its title, name or
 * id, else the object itself.
 *
 * @param {object} ref - The embedded object
 * @return {string|number|object} The label
 */
function embeddedRefLabel(ref) {
	return ref.title || ref.name || ref.id || ref
}

/**
 * The label of a register or schema id: the title of the listed entry with
 * that id, else the id itself.
 *
 * @param {string|number} ref - The id
 * @param {Array<object>} available - The listed registers or schemas
 * @return {string|number} The label
 */
function listedRefLabel(ref, available) {
	const listed = available.find((entry) => entry.id === ref)
	return listed?.title || ref
}

/**
 * The label of a register or schema reference, embedded or by id.
 *
 * @param {object|string|number|undefined} ref - The reference
 * @param {Array<object>} available - The listed registers or schemas
 * @return {string|number|object} The label
 */
export function refLabel(ref, available) {
	if (!ref) return 'Not set'
	if (typeof ref === 'object') return embeddedRefLabel(ref)
	return listedRefLabel(ref, available)
}

/**
 * The description of a lock object: who holds it, since when, and for
 * which process.
 *
 * @param {object} locked - The lock
 * @return {string} The description
 */
function lockDescription(locked) {
	const lockedBy = locked.lockedBy || 'Unknown user'
	const lockedAt = formatDateOr(locked.lockedAt, 'Unknown time')
	const proc = locked.process ? ` (${locked.process})` : ''
	return `Locked by ${lockedBy} at ${lockedAt}${proc}`
}

/**
 * The label of an object's lock state.
 *
 * @param {object|boolean|undefined} locked - The `@self.locked` value
 * @return {string} The label
 */
export function lockLabel(locked) {
	if (!locked) return 'Not locked'
	if (typeof locked !== 'object') return 'Locked'
	return lockDescription(locked)
}

/**
 * The metadata rows of an object: id, version, register, schema, lock and
 * the lifecycle dates.
 *
 * @param {object} obj - The object
 * @param {{registers: Array<object>, schemas: Array<object>}} lists - The listed registers and schemas
 * @return {Array<Array>} The rows
 */
export function metadataRows(obj, { registers, schemas }) {
	const self = obj['@self'] || {}
	return [
		['ID', obj.id || 'Not set'],
		['Version', self.version || 'Not set'],
		['Register', refLabel(self.register, registers)],
		['Schema', refLabel(self.schema, schemas)],
		['Locked', lockLabel(self.locked)],
		['Created', formatDateOr(self.created, 'Not set')],
		['Updated', formatDateOr(self.updated, 'Not set')],
		['Published', formatDateOr(self.published, 'Not published')],
		['Depublished', formatDateOr(self.depublished, 'Not depublished')],
	]
}

/**
 * The name an object carries on `@self` or on itself.
 *
 * @param {object} obj - The object
 * @return {string|undefined} The name
 */
function ownName(obj) {
	return obj['@self']?.name || obj.name
}

/**
 * The display name of an object in the modal title.
 *
 * @param {object} obj - The object
 * @return {string} The name
 */
export function objectDisplayName(obj) {
	return ownName(obj) || obj.title || obj.id || 'Untitled'
}

/**
 * The schema reference of an object, on itself or on `@self`.
 *
 * @param {object} obj - The object
 * @return {object|string|number|null|undefined} The reference
 */
function rawSchemaOf(obj) {
	return obj.schema ?? obj['@self']?.schema
}

/**
 * The name of an embedded schema in the modal title.
 *
 * @param {object} schema - The schema
 * @return {string|number} The name
 */
function embeddedSchemaName(schema) {
	return schema.title || schema.name || schema.id || 'Publication'
}

/**
 * The name of a schema id in the modal title: the listed schema's title or
 * name, else the id as text.
 *
 * @param {string|number} schemaId - The id
 * @param {Array<object>} schemas - The listed schemas
 * @return {string} The name
 */
function listedSchemaName(schemaId, schemas) {
	const match = schemas.find((s) => Number(s.id) === Number(schemaId))
	return match?.title || match?.name || String(schemaId)
}

/**
 * The schema name of an object in the modal title.
 *
 * @param {object} obj - The object
 * @param {Array<object>} schemas - The listed schemas
 * @return {string|number} The name
 */
export function objectSchemaName(obj, schemas) {
	const rawSchema = rawSchemaOf(obj)
	if (rawSchema && typeof rawSchema === 'object') {
		return embeddedSchemaName(rawSchema)
	}
	if (!isBlankValue(rawSchema)) {
		return listedSchemaName(rawSchema, schemas)
	}
	return 'Publication'
}

/**
 * Whether a value is null, undefined or the empty string.
 *
 * @param {*} value - The value
 * @return {boolean} True when blank
 */
export function isBlankValue(value) {
	return value === null || value === undefined || value === ''
}

/**
 * The id a schema reference points at: an embedded object's id, uuid or
 * slug, or the reference itself.
 *
 * @param {object|string|number} schemaRef - The reference
 * @return {string|number|undefined} The id
 */
function schemaRefId(schemaRef) {
	return typeof schemaRef === 'object'
		? (schemaRef.id ?? schemaRef.uuid ?? schemaRef.slug)
		: schemaRef
}

/**
 * The listed schema whose id, uuid or slug reads as the given text.
 *
 * @param {Array<object>} schemas - The listed schemas
 * @param {string} idStr - The id as text
 * @return {object|null} The schema
 */
function findSchemaByText(schemas, idStr) {
	return (
		schemas.find(
			(schema) =>
				String(schema.id) === idStr
				|| String(schema.uuid) === idStr
				|| String(schema.slug) === idStr,
		) || null
	)
}

/**
 * The full schema an object's `@self.schema` refers to: the embedded schema
 * when it carries properties, else the listed schema matching its id, uuid
 * or slug.
 *
 * @param {object|string|number|undefined} schemaRef - The reference
 * @param {Array<object>} schemas - The listed schemas
 * @return {object|null} The schema
 */
export function referencedSchema(schemaRef, schemas) {
	if (!schemaRef) return null

	// `@self.schema` is the full embedded schema object when the API
	// extends it; prefer it directly so we keep `properties`.
	if (typeof schemaRef === 'object' && schemaRef.properties) {
		return schemaRef
	}

	// The id in @self.schema arrives as a string (e.g. "1") while the listed
	// ids are numbers — match on id, uuid and slug as text.
	const schemaId = schemaRefId(schemaRef)
	if (isBlankValue(schemaId)) return null
	return findSchemaByText(schemas, String(schemaId))
}

/**
 * The listed schema whose id (as text), uuid or slug equals the given id.
 *
 * @param {Array<object>} schemas - The listed schemas
 * @param {string|number} schemaId - The id
 * @return {object|undefined} The schema
 */
export function findSchemaByAnyId(schemas, schemaId) {
	return schemas.find(
		(schema) =>
			String(schema.id) === String(schemaId)
			|| schema.uuid === schemaId
			|| schema.slug === schemaId,
	)
}

/**
 * The id used to look up the properties of a schema reference: an embedded
 * object's id or uuid, or the reference itself.
 *
 * @param {object|string|number} schemaRef - The reference
 * @return {string|number|undefined} The id
 */
function propertiesLookupId(schemaRef) {
	return typeof schemaRef === 'object' ? schemaRef.id || schemaRef.uuid : schemaRef
}

/**
 * The properties of an embedded schema object, when it carries any.
 *
 * @param {object|string|number} schemaRef - The reference
 * @return {object|null} The properties
 */
function embeddedSchemaProperties(schemaRef) {
	return typeof schemaRef === 'object' && schemaRef.properties
		? schemaRef.properties
		: null
}

/**
 * The properties of the schema an object's `@self.schema` refers to: the
 * embedded schema's own properties, else the listed schema's, else none.
 *
 * @param {object|string|number|undefined} schemaRef - The reference
 * @param {Array<object>} schemas - The listed schemas
 * @return {object} The properties
 */
export function referencedSchemaProperties(schemaRef, schemas) {
	if (!schemaRef) return {}

	// If schema is already an extended object, use its properties directly
	const embedded = embeddedSchemaProperties(schemaRef)
	if (embedded) return embedded

	// @self.schema can be a UUID string, numeric ID, or slug — match against all
	const schemaId = propertiesLookupId(schemaRef)
	if (!schemaId) return {}
	return findSchemaByAnyId(schemas, schemaId)?.properties || {}
}
