/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Publication totals per status, counted by OpenRegister instead of in the browser.
 *
 * The status of a publication is derived from two dates (see publicationStatus.js):
 *   concept     → no publicationDate, or one in the future
 *   published   → publicationDate ≤ now and no depublicationDate ≤ now
 *   depublished → publicationDate ≤ now and depublicationDate ≤ now
 *
 * OpenRegister's ad-hoc aggregation (`.../aggregations/{register}/{schema}/value`)
 * counts with AND-ed operator filters, which cannot say "absent OR in the future"
 * directly. Three counts that only need `lte` give all three statuses exactly:
 *   total, live (publicationDate ≤ now), ended (live and depublicationDate ≤ now)
 *   concept = total − live, published = live − ended, depublished = ended.
 */

/**
 * The query string of one ad-hoc count with optional `lte` filters.
 *
 * @param {object<string, string>} lteFilters Field → upper bound (inclusive).
 * @return {string} The query string, without the leading `?`.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function countQuery(lteFilters = {}) {
	const params = new URLSearchParams({ metric: 'count' })
	for (const [field, bound] of Object.entries(lteFilters)) {
		params.append(`filter[${field}][lte]`, bound)
	}
	return params.toString()
}

/**
 * The filters of the three counts the statuses are derived from.
 *
 * @param {string} nowIso The moment to measure at, as an ISO 8601 string.
 * @return {{total: object, live: object, ended: object}} Field → `lte` bound per count.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function statusCountFilters(nowIso) {
	return {
		total: {},
		live: { publicationDate: nowIso },
		ended: { publicationDate: nowIso, depublicationDate: nowIso },
	}
}

/**
 * Derive the per-status totals from the three counts.
 *
 * @param {{total: ?number, live: ?number, ended: ?number}} counts The three counts.
 * @return {{total: number, concept: number, published: number, depublished: number}|null}
 *   The totals, or null when a count is missing or the counts contradict each other.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function deriveStatusCounts({ total, live, ended }) {
	const valid = [total, live, ended].every((n) => Number.isInteger(n) && n >= 0)
	if (!valid || live > total || ended > live) {
		return null
	}
	return {
		total,
		concept: total - live,
		published: live - ended,
		depublished: ended,
	}
}

/**
 * Read one ad-hoc count.
 *
 * @param {function(string, object): Promise<Response>} fetchFn The fetch implementation.
 * @param {string} url The aggregation URL, query included.
 * @param {object} headers The request headers.
 * @return {Promise<number|null>} The count, or null when the request or its answer fails.
 */
async function readCount(fetchFn, url, headers) {
	const response = await fetchFn(url, { method: 'GET', headers })
	if (!response.ok) {
		return null
	}
	const data = await response.json()
	return typeof data?.value === 'number' ? data.value : null
}

/**
 * Count the publications per status through OpenRegister's ad-hoc aggregation.
 *
 * @param {object} options The request.
 * @param {string} options.register The publication register id.
 * @param {string} options.schema The publication schema id.
 * @param {string} [options.prefix] Path prefix, e.g. `/index.php`.
 * @param {object} [options.headers] Request headers.
 * @param {Date} [options.now] The moment to measure at.
 * @param {function(string, object): Promise<Response>} [options.fetchFn] The fetch implementation.
 * @return {Promise<{total: number, concept: number, published: number, depublished: number}|null>}
 *   The totals, or null when the register or schema is unknown or a count fails.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export async function fetchPublicationStatusCounts({
	register,
	schema,
	prefix = '',
	headers = {},
	now = new Date(),
	fetchFn = (...args) => fetch(...args),
}) {
	if (!register || !schema) {
		return null
	}
	const base = `${prefix}/apps/openregister/api/objects/aggregations/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/value`
	const filters = statusCountFilters(now.toISOString())
	try {
		const [total, live, ended] = await Promise.all(
			['total', 'live', 'ended'].map((key) =>
				readCount(fetchFn, `${base}?${countQuery(filters[key])}`, headers),
			),
		)
		return deriveStatusCounts({ total, live, ended })
	} catch {
		return null
	}
}
