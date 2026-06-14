/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Usage-analytics frontend service. Fetches privacy-safe per-publication and
 * per-catalog usage statistics from the authenticated stats API and provides
 * pure presentation helpers (totals formatting, trend derivation, counting-start
 * note) used by the detail stats panel and the most-viewed dashboard widget.
 *
 * Counts are requests, NOT unique visitors — the UI states this explicitly so
 * officers do not misread the numbers (ANA-002 honest-limitation requirement).
 */
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

/**
 * Fetch usage statistics for a single publication.
 *
 * @param {string} id Publication UUID.
 * @param {object} [opts] Optional { from, to, granularity }.
 * @return {Promise<object>} The stats payload (views, downloads, series, countingStart).
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export async function fetchPublicationStats(id, opts = {}) {
	const url = generateUrl('/apps/opencatalogi/api/publications/{id}/stats', { id })
	const response = await axios.get(url, { params: opts })
	return response.data
}

/**
 * Fetch usage roll-ups and top-N for a catalog.
 *
 * @param {string} slug Catalog slug.
 * @param {object} [opts] Optional { from, to, top }.
 * @return {Promise<object>} The catalog stats payload.
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export async function fetchCatalogStats(slug, opts = {}) {
	const url = generateUrl('/apps/opencatalogi/api/catalogs/{slug}/stats', { slug })
	const response = await axios.get(url, { params: opts })
	return response.data
}

/**
 * Build the authenticated CSV export URL for a catalog + range.
 *
 * @param {string} slug Catalog slug.
 * @param {object} [opts] Optional { from, to }.
 * @return {string} The export URL (caller navigates/downloads).
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export function catalogExportUrl(slug, opts = {}) {
	const base = generateUrl('/apps/opencatalogi/api/catalogs/{slug}/stats/export', { slug })
	const params = new URLSearchParams()
	if (opts.from) params.set('from', opts.from)
	if (opts.to) params.set('to', opts.to)
	const qs = params.toString()
	return qs ? `${base}?${qs}` : base
}

/**
 * Format a count for display (compact thousands separators).
 *
 * @param {number} value Raw count.
 * @return {string} Human-readable count.
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export function formatCount(value) {
	const n = Number(value) || 0
	return n.toLocaleString()
}

/**
 * Derive a simple recent trend from a daily series.
 *
 * Compares the sum of the most-recent half of the window against the older
 * half; returns 'up' | 'down' | 'flat'. Pure function, independently testable.
 *
 * @param {Array<{date:string, views:number}>} series Daily series (chronological).
 * @param {string} [metric] 'views' or 'downloads'.
 * @return {'up'|'down'|'flat'} The trend direction.
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export function deriveTrend(series, metric = 'views') {
	if (!Array.isArray(series) || series.length < 2) {
		return 'flat'
	}
	const mid = Math.floor(series.length / 2)
	const older = series.slice(0, mid).reduce((sum, d) => sum + (Number(d[metric]) || 0), 0)
	const recent = series.slice(mid).reduce((sum, d) => sum + (Number(d[metric]) || 0), 0)
	if (recent > older) return 'up'
	if (recent < older) return 'down'
	return 'flat'
}

/**
 * Build the counting-start note when a publication predates measurement.
 *
 * @param {?string} countingStart First measured day (YYYY-MM-DD) or null.
 * @param {function} t Translation function t('opencatalogi', key, params).
 * @return {?string} The note, or null when there is nothing to qualify.
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export function countingStartNote(countingStart, t) {
	if (!countingStart) {
		return t('opencatalogi', 'No usage has been measured yet.')
	}
	return t('opencatalogi', 'Counting started on {date}. Earlier reach is not measured.', { date: countingStart })
}
