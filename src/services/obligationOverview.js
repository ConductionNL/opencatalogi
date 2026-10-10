/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the Woo-verplichtingen page shows from `GET /api/obligations`
 * (REQ-WOO-003). The board OcWooVerplichtingen draws four totals, a table per
 * source system and the outstanding records, late first. The backend states
 * each obligation (`published`, `late`, `due`, `unknown`); this module only
 * counts and orders.
 */

const DAY = 24 * 60 * 60 * 1000

/**
 * Parse a date, or null when it cannot be read.
 *
 * @param {string|null|undefined} value An ISO 8601 date.
 * @return {Date|null}
 */
function parse(value) {
	if (!value) return null
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * Build the page model.
 *
 * @param {object|null|undefined} overview The response of `GET /api/obligations`.
 * @param {Date} now The moment.
 * @return {{tiles: object, rows: Array<object>, perSource: Array<object>, unreadSources: Array<object>}}
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-an-admin-can-open-the-overview-as-a-page-req-woo-003
 */
export function obligationPage(overview, now = new Date()) {
	const source = overview || {}
	const obligations = Array.isArray(source.obligations) ? source.obligations : []
	const unreadSources = Array.isArray(source.unreadSources)
		? source.unreadSources
		: []
	const weekEnd = new Date(now.getTime() + 7 * DAY)

	const outstanding = obligations.filter((row) => row.state !== 'published')
	const publishedThisMonth = obligations.filter((row) => {
		const at = parse(row.publishedAt)
		return (
			row.state === 'published'
			&& at !== null
			&& at.getUTCFullYear() === now.getUTCFullYear()
			&& at.getUTCMonth() === now.getUTCMonth()
		)
	})
	const onTime = publishedThisMonth.filter((row) => {
		const due = parse(row.dueDate)
		return due !== null && parse(row.publishedAt) <= due
	})

	const rows = outstanding
		.map((row) => {
			const due = parse(row.dueDate)
			const late = row.state === 'late'
			return {
				title: row.title || '',
				source: row.source || '',
				category: row.category || '',
				recordReference: row.recordReference || '',
				dueDate: due ? row.dueDate : null,
				late,
				daysLate:
					late && due
						? Math.floor((now.getTime() - due.getTime()) / DAY)
						: 0,
			}
		})
		.sort((a, b) => {
			if (a.late !== b.late) return a.late ? -1 : 1
			if (a.dueDate === null || b.dueDate === null)
				return (a.dueDate === null) - (b.dueDate === null)
			return parse(a.dueDate) - parse(b.dueDate)
		})

	const bySource = new Map()
	for (const row of rows) {
		const entry = bySource.get(row.source) || {
			source: row.source,
			outstanding: 0,
			nextDue: null,
			late: 0,
		}
		entry.outstanding++
		if (row.late) entry.late++
		if (
			row.dueDate !== null
			&& (entry.nextDue === null || parse(row.dueDate) < parse(entry.nextDue))
		) {
			entry.nextDue = row.dueDate
		}
		bySource.set(row.source, entry)
	}

	return {
		tiles: {
			outstanding: outstanding.length,
			sources: bySource.size,
			dueThisWeek: outstanding.filter((row) => {
				const due = parse(row.dueDate)
				return row.state === 'due' && due !== null && due <= weekEnd
			}).length,
			late: outstanding.filter((row) => row.state === 'late').length,
			publishedThisMonth: publishedThisMonth.length,
			onTimeThisMonth: onTime.length,
		},
		rows,
		perSource: [...bySource.values()].sort((a, b) =>
			a.source.localeCompare(b.source),
		),
		unreadSources,
	}
}
