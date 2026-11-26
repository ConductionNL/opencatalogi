/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Publication totals per status, counted by OpenRegister instead of in the browser.
 *
 * The status of a publication is derived from two dates (see publicationStatus.js).
 * OpenRegister's ad-hoc aggregation (`.../aggregations/{register}/{schema}/value`)
 * counts with AND-ed operator filters, so each status is a difference of counts:
 *   depublished = withdrawn (depublicationDate ≤ now)
 *   published   = live (publicationDate ≤ now) − ended (live and withdrawn)
 *   concept     = undated (no publicationDate) − undated and withdrawn
 *   scheduled   = what is left, minus the invalid windows
 * An invalid window (depublicationDate ≤ publicationDate, both ahead) compares two
 * fields, which no filter can, so those few publications are read and checked here.
 */

/** The most pages of upcoming windows read before giving up. */
const MAX_WINDOW_PAGES = 20

/**
 * The filter parameters of a query.
 *
 * @param {Object<string, Object<string, string>>} filters Field → operator → value.
 * @param {URLSearchParams} [params] The parameters to add them to.
 * @return {URLSearchParams} The parameters.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
function withFilters(filters, params = new URLSearchParams()) {
	for (const [field, operators] of Object.entries(filters)) {
		for (const [operator, value] of Object.entries(operators)) {
			params.append(`filter[${field}][${operator}]`, value)
		}
	}
	return params
}

/**
 * The query string of one ad-hoc count with optional filters.
 *
 * @param {Object<string, Object<string, string>>} filters Field → operator → value.
 * @return {string} The query string, without the leading `?`.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function countQuery(filters = {}) {
	return withFilters(filters, new URLSearchParams({ metric: 'count' })).toString()
}

/**
 * The filters of the counts the statuses are derived from.
 *
 * @param {string} nowIso The moment to measure at, as an ISO 8601 string.
 * @return {Object<string, Object>} Count name → its filters.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function statusCountFilters(nowIso) {
	const passed = { lte: nowIso }
	const absent = { isnull: 'true' }
	return {
		total: {},
		live: { publicationDate: passed },
		ended: { publicationDate: passed, depublicationDate: passed },
		withdrawn: { depublicationDate: passed },
		undated: { publicationDate: absent },
		undatedWithdrawn: { publicationDate: absent, depublicationDate: passed },
	}
}

/**
 * Derive the per-status totals from the counts.
 *
 * @param {Object<string, ?number>} counts The counts of `statusCountFilters`, plus `invalid`.
 * @return {{total: number, concept: number, scheduled: number, published: number, depublished: number, invalid: number}|null}
 *   The totals, or null when a count is missing or the counts contradict each other.
 * @spec openspec/changes/dashboard-consume-or-aggregations/tasks.md
 */
export function deriveStatusCounts(counts) {
	const { total, live, ended, withdrawn, undated, undatedWithdrawn, invalid } = counts
	const valid = [total, live, ended, withdrawn, undated, undatedWithdrawn, invalid]
		.every((n) => Number.isInteger(n) && n >= 0)
	if (!valid || ended > live || ended > withdrawn || undatedWithdrawn > undated || undatedWithdrawn > withdrawn) {
		return null
	}
	const published = live - ended
	const concept = undated - undatedWithdrawn
	const scheduled = total - withdrawn - published - concept - invalid
	if (scheduled < 0) {
		return null
	}
	return { total, concept, scheduled, published, depublished: withdrawn, invalid }
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
 * Whether a window closes on or before it opens.
 *
 * @param {object} publication A publication with both dates.
 * @return {boolean} True for an invalid window.
 */
function closesBeforeOpening(publication) {
	const opens = new Date(publication?.publicationDate).getTime()
	const closes = new Date(publication?.depublicationDate).getTime()
	return !Number.isNaN(opens) && !Number.isNaN(closes) && closes <= opens
}

/**
 * Count the invalid windows among the publications whose both dates lie ahead.
 *
 * @param {function(string, object): Promise<Response>} fetchFn The fetch implementation.
 * @param {string} listUrl The objects list URL, without query.
 * @param {string} nowIso The moment to measure at.
 * @param {object} headers The request headers.
 * @return {Promise<number|null>} The count, or null when a page cannot be read.
 */
async function countInvalidWindows(fetchFn, listUrl, nowIso, headers) {
	const filters = { publicationDate: { gt: nowIso }, depublicationDate: { gt: nowIso } }
	let invalid = 0
	for (let page = 1; page <= MAX_WINDOW_PAGES; page++) {
		const params = withFilters(filters)
		params.append('_fields', 'publicationDate,depublicationDate')
		params.append('_limit', '500')
		params.append('_page', String(page))
		const response = await fetchFn(`${listUrl}?${params}`, { method: 'GET', headers })
		if (!response.ok) {
			return null
		}
		const data = await response.json()
		if (!Array.isArray(data?.results)) {
			return null
		}
		invalid += data.results.filter(closesBeforeOpening).length
		if (!(page < Number(data.pages))) {
			return invalid
		}
	}
	return null
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
 * @return {Promise<{total: number, concept: number, scheduled: number, published: number, depublished: number, invalid: number}|null>}
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
	const pair = `${encodeURIComponent(register)}/${encodeURIComponent(schema)}`
	const base = `${prefix}/apps/openregister/api/objects`
	const nowIso = now.toISOString()
	const filters = statusCountFilters(nowIso)
	try {
		const names = Object.keys(filters)
		const values = await Promise.all(
			names.map((name) =>
				readCount(fetchFn, `${base}/aggregations/${pair}/value?${countQuery(filters[name])}`, headers),
			),
		)
		const counts = Object.fromEntries(names.map((name, i) => [name, values[i]]))
		counts.invalid = await countInvalidWindows(fetchFn, `${base}/${pair}`, nowIso, headers)
		return deriveStatusCounts(counts)
	} catch {
		return null
	}
}
