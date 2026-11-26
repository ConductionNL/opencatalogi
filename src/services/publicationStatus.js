/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Centralised publication-status helpers.
 *
 * Status is derived purely from the object's own properties:
 *   publicationDate   – the date the publication goes (or went) live
 *   depublicationDate – the date the publication was (or will be) withdrawn
 *
 * Rules (the publication matrix):
 *   depublished → depublicationDate ≤ now, whatever publicationDate is
 *   concept     → no publicationDate (and no depublicationDate ≤ now)
 *   published   → publicationDate ≤ now (and no depublicationDate ≤ now)
 *   invalid     → publicationDate > now and depublicationDate ≤ publicationDate:
 *                 the window closes before it opens, so it never goes live
 *   scheduled   → any other publicationDate > now
 */

/**
 *
 * @param value
 */
function toDate(value) {
	if (!value) return null
	const d = new Date(value)
	return isNaN(d.getTime()) ? null : d
}

/**
 * Whether the publication window closes on or before it opens while it has
 * not opened yet, so the publication never goes live (matrix row 6). Stays
 * true once the depublication date has passed, when the status is depublished.
 *
 * @param {object} obj Publication object
 * @return {boolean} True for an invalid window.
 * @spec openspec/specs/publications/spec.md
 */
export function hasInvalidWindow(obj) {
	const pub = toDate(obj?.publicationDate)
	const depub = toDate(obj?.depublicationDate)
	return !!(pub && depub && pub > new Date() && depub <= pub)
}

/**
 * Returns 'concept', 'scheduled', 'published', 'depublished' or 'invalid'.
 *
 * @param {object} obj Publication object
 * @return {string} The status.
 * @spec openspec/specs/publications/spec.md
 */
export function getPublicationStatus(obj) {
	const now = new Date()
	const pub = toDate(obj?.publicationDate)
	const depub = toDate(obj?.depublicationDate)
	if (depub && depub <= now) return 'depublished'
	if (!pub) return 'concept'
	if (pub <= now) return 'published'
	if (depub && depub <= pub) return 'invalid'
	return 'scheduled'
}

/**
 * @param obj
 * @spec openspec/specs/publications/spec.md
 */
export function isPublished(obj) {
	return getPublicationStatus(obj) === 'published'
}

/**
 * @param obj
 * @spec openspec/specs/publications/spec.md
 */
export function isDepublished(obj) {
	return getPublicationStatus(obj) === 'depublished'
}

/**
 * @param obj
 * @spec openspec/specs/publications/spec.md
 */
export function isConcept(obj) {
	return getPublicationStatus(obj) === 'concept'
}

/**
 * @param {object} obj Publication object
 * @return {boolean} True when it goes live on a coming publication date.
 * @spec openspec/specs/publications/spec.md
 */
export function isScheduled(obj) {
	return getPublicationStatus(obj) === 'scheduled'
}
