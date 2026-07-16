/**
 * Centralised publication-status helpers.
 *
 * Status is derived purely from the object's own properties:
 *   publicatiedatum   – the date the publication goes (or went) live
 *   depublicatiedatum – the date the publication was (or will be) withdrawn
 *
 * Rules:
 *   published   → publicatiedatum ≤ now AND (no depublicatiedatum OR depublicatiedatum > now)
 *   depublished → publicatiedatum ≤ now AND depublicatiedatum ≤ now
 *   concept     → no publicatiedatum OR publicatiedatum > now
 */

function toDate(value) {
	if (!value) return null
	const d = new Date(value)
	return isNaN(d.getTime()) ? null : d
}

/** @spec openspec/specs/publications/spec.md */
export function isPublished(obj) {
	const pub = toDate(obj?.publicatiedatum)
	if (!pub) return false
	const now = new Date()
	if (pub > now) return false
	const depub = toDate(obj?.depublicatiedatum)
	return !depub || depub > now
}

/** @spec openspec/specs/publications/spec.md */
export function isDepublished(obj) {
	const pub = toDate(obj?.publicatiedatum)
	if (!pub) return false
	const now = new Date()
	if (pub > now) return false
	const depub = toDate(obj?.depublicatiedatum)
	return !!(depub && depub <= now)
}

/** @spec openspec/specs/publications/spec.md */
export function isConcept(obj) {
	const pub = toDate(obj?.publicatiedatum)
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
