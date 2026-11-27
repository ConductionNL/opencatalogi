/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publication window of an object of any schema: which of its properties
 * hold the publication (P) and depublication (D) date, where it stands between
 * them, and what publishing or depublishing it writes.
 *
 *   Concept     P not set and D not in the past
 *   Scheduled   P > today
 *   Published   P ≤ today and (D not set or D > today)
 *   Depublished D set and D ≤ today, whatever P is
 *
 * Dates compare as YYYY-MM-DD, so "today" counts as reached.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
 */

import { resolveSchema } from './schemaHelpers.js'

/** The property names each date may have, the English one first. */
const FIELD_NAMES = {
	publication: ['publicationDate', 'publicatiedatum'],
	depublication: ['depublicationDate', 'depublicatiedatum'],
}

/**
 * A date value as YYYY-MM-DD, or null when it is empty.
 *
 * @param {unknown} value The stored value.
 * @return {string|null} The day.
 */
function day(value) {
	if (value === null || value === undefined || value === '') return null
	return String(value).slice(0, 10)
}

/**
 * The properties of an object's schema that hold its publication and
 * depublication date, each null when the schema declares neither name.
 *
 * @param {object} obj The object.
 * @return {{publication: string|null, depublication: string|null}} The property names.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
 */
export function publicationDateFields(obj) {
	const props = resolveSchema(obj)?.properties
	const declared = (names) =>
		(props && typeof props === 'object'
			? names.find((name) => Boolean(props[name]))
			: undefined) ?? null

	return {
		publication: declared(FIELD_NAMES.publication),
		depublication: declared(FIELD_NAMES.depublication),
	}
}

/**
 * Where an object stands in its publication window.
 *
 * @param {object} obj The object.
 * @param {string} today Today as YYYY-MM-DD.
 * @param {{publication: string|null, depublication: string|null}} [fields] Its date properties.
 * @return {'concept'|'scheduled'|'published'|'depublished'} The status.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
 */
export function publicationWindowStatus(obj, today, fields = publicationDateFields(obj)) {
	const published = fields.publication ? day(obj?.[fields.publication]) : null
	const depublished = fields.depublication ? day(obj?.[fields.depublication]) : null

	if (depublished !== null && depublished <= today) return 'depublished'
	if (published === null) return 'concept'
	if (published > today) return 'scheduled'
	return 'published'
}

/**
 * What depublishing an object writes, or null when it is left alone: its
 * schema has no depublication date, or it is a concept or already depublished.
 * A published object gets the date as its depublication date. A scheduled one
 * gets it too when it falls after its publication date; one on or before it
 * would leave a window that never opens, so the publication date is cleared
 * instead and the object goes back to concept.
 *
 * @param {object} obj The object.
 * @param {string} date The depublication date, YYYY-MM-DD.
 * @param {string} today Today as YYYY-MM-DD.
 * @return {object|null} The properties to write.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
 */
export function depublishPatch(obj, date, today) {
	const fields = publicationDateFields(obj)
	if (!fields.depublication) return null

	const status = publicationWindowStatus(obj, today, fields)
	if (status === 'published') {
		return { [fields.depublication]: date }
	}
	if (status !== 'scheduled') return null

	return date > day(obj[fields.publication])
		? { [fields.depublication]: date }
		: { [fields.publication]: null }
}

/**
 * What publishing an object writes, or null when its schema has no
 * publication date: the date as its publication date (kept as it is when
 * `date` is null), and an empty depublication date when the schema has one.
 *
 * @param {object} obj The object.
 * @param {string|null} date The publication date, YYYY-MM-DD, or null to keep it.
 * @return {object|null} The properties to write.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
 */
export function publishPatch(obj, date) {
	const fields = publicationDateFields(obj)
	if (!fields.publication) return null

	const patch = date === null ? {} : { [fields.publication]: date }
	if (fields.depublication) {
		patch[fields.depublication] = null
	}
	return patch
}

/**
 * Whether a row offers Publish: its schema has a publication date and it is
 * not published yet.
 *
 * @param {object} obj The row.
 * @param {string} today Today as YYYY-MM-DD.
 * @return {boolean} True when it does.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-a-row-can-be-published-or-depublished-on-its-own-psa-005
 */
export function canPublish(obj, today) {
	const fields = publicationDateFields(obj)
	return Boolean(fields.publication)
		&& publicationWindowStatus(obj, today, fields) !== 'published'
}

/**
 * Whether a row offers Depublish: its schema has a depublication date and it
 * is published or scheduled.
 *
 * @param {object} obj The row.
 * @param {string} today Today as YYYY-MM-DD.
 * @return {boolean} True when it does.
 *
 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-a-row-can-be-published-or-depublished-on-its-own-psa-005
 */
export function canDepublish(obj, today) {
	return depublishPatch(obj, today, today) !== null
}

/**
 * Today as YYYY-MM-DD, the way the mass dialogs write it.
 *
 * @return {string} Today.
 */
export function todayDate() {
	return new Date().toISOString().slice(0, 10)
}
