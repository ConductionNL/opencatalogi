/**
 * Centralised retention-status helpers.
 *
 * Retention status is derived purely from an object's own retention fields and
 * the configured warning window, mirroring the consume-not-build approach of
 * publicationStatus.js. No server round-trip is required to classify an object.
 *
 *   expiring soon  → retentionExpiresAt within [now, now + window] and not archived
 *   review required → retentionExpiresAt < now, retentionAction = review, not archived
 *   expired        → retentionExpiresAt < now (any action), not archived
 *   archived       → status = archived
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */

const DEFAULT_WARNING_WINDOW_DAYS = 30

function toDate(value) {
	if (!value) return null
	const d = new Date(value)
	return isNaN(d.getTime()) ? null : d
}

/**
 * Compute the retention expiry from a publication date and term in months.
 *
 * @param {string} publicationDate ISO-8601 publication date.
 * @param {number} termMonths      Retention term in months.
 * @return {string|null} ISO-8601 expiry, or null when not computable.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-metadata-on-the-publication-schema-ret-003
 */
export function computeExpiry(publicationDate, termMonths) {
	const base = toDate(publicationDate)
	const months = Number(termMonths)
	if (!base || !Number.isFinite(months) || months <= 0) return null
	const expiry = new Date(base.getTime())
	expiry.setMonth(expiry.getMonth() + months)
	return expiry.toISOString()
}

/**
 * Returns true when the object is archived.
 *
 * @param {object} obj Publication object.
 * @return {boolean} Whether archived.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-archived-state-semantics-ret-006
 */
export function isArchived(obj) {
	return obj?.status === 'archived'
}

/**
 * Returns true when the retention term is expiring within the warning window.
 *
 * @param {object} obj        Publication object.
 * @param {number} windowDays Warning window in days.
 * @return {boolean} Whether expiring soon.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
 */
export function isExpiringSoon(obj, windowDays = DEFAULT_WARNING_WINDOW_DAYS) {
	if (isArchived(obj)) return false
	const expiry = toDate(obj?.retentionExpiresAt)
	if (!expiry) return false
	const now = new Date()
	if (expiry <= now) return false
	const windowEnd = new Date(now.getTime() + windowDays * 24 * 60 * 60 * 1000)
	return expiry <= windowEnd
}

/**
 * Returns true when the term has expired and the action requires human review.
 *
 * @param {object} obj Publication object.
 * @return {boolean} Whether review is required.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
export function isReviewRequired(obj) {
	if (isArchived(obj)) return false
	const expiry = toDate(obj?.retentionExpiresAt)
	if (!expiry) return false
	return expiry <= new Date() && (obj?.retentionAction || 'review') === 'review'
}

/**
 * Returns the retention status label for an object.
 *
 * @param {object} obj        Publication object.
 * @param {number} windowDays Warning window in days.
 * @return {string} One of: archived, review-required, expiring-soon, ok.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
export function getRetentionStatus(obj, windowDays = DEFAULT_WARNING_WINDOW_DAYS) {
	if (isArchived(obj)) return 'archived'
	if (isReviewRequired(obj)) return 'review-required'
	if (isExpiringSoon(obj, windowDays)) return 'expiring-soon'
	return 'ok'
}
