/**
 * Centralised publication-status helpers.
 *
 * Status is derived purely from the object's own properties:
 *   publicationDate   – the date the publication goes (or went) live
 *   depublicationDate – the date the publication was (or will be) withdrawn
 *
 * Rules:
 *   published   → publicationDate ≤ now AND (no depublicationDate OR depublicationDate > now)
 *   depublished → publicationDate ≤ now AND depublicationDate ≤ now
 *   concept     → no publicationDate OR publicationDate > now
 */

function toDate(value) {
	if (!value) return null
	const d = new Date(value)
	return isNaN(d.getTime()) ? null : d
}

/** @spec openspec/specs/publications/spec.md */
export function isPublished(obj) {
	const pub = toDate(obj?.publicationDate)
	if (!pub) return false
	const now = new Date()
	if (pub > now) return false
	const depub = toDate(obj?.depublicationDate)
	return !depub || depub > now
}

/** @spec openspec/specs/publications/spec.md */
export function isDepublished(obj) {
	const pub = toDate(obj?.publicationDate)
	if (!pub) return false
	const now = new Date()
	if (pub > now) return false
	const depub = toDate(obj?.depublicationDate)
	return !!(depub && depub <= now)
}

/** @spec openspec/specs/publications/spec.md */
export function isConcept(obj) {
	const pub = toDate(obj?.publicationDate)
	return !pub || pub > new Date()
}

/**
 * Returns 'published', 'depublished', or 'concept'.
 *
 * @param {object} obj Publication object
 *
 * @spec openspec/specs/publications/spec.md
 */
export function getPublicationStatus(obj) {
	if (isDepublished(obj)) return 'depublished'
	if (isPublished(obj)) return 'published'
	return 'concept'
}
