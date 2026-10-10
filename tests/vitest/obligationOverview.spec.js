/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/obligationOverview.js: what the Woo-verplichtingen
 * page shows from `GET /api/obligations` (REQ-WOO-003, board OcWooVerplichtingen).
 * Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import { obligationPage } from '../../src/services/obligationOverview.js'

const now = new Date('2026-10-08T10:00:00Z')

const overview = {
	total: 5,
	published: 2,
	late: 1,
	outstanding: 3,
	sourcesRead: 2,
	sourcesRegistered: 3,
	unreadSources: [
		{ appId: 'decos', reason: 'No reader answered for this source.' },
	],
	obligations: [
		{
			title: 'Besluit jeugdzorg',
			source: 'dossiq',
			state: 'late',
			dueDate: '2026-10-06T00:00:00Z',
			category: 'infocat014',
			recordReference: 'u1',
		},
		{
			title: 'Besluitenlijst raad',
			source: 'notubiz',
			state: 'due',
			dueDate: '2026-10-09T00:00:00Z',
			category: 'infocat004',
		},
		{ title: 'Convenant', source: 'notubiz', state: 'unknown' },
		{
			title: 'Besluit Molenweg',
			source: 'dossiq',
			state: 'published',
			publishedAt: '2026-10-02T00:00:00Z',
			dueDate: '2026-10-05T00:00:00Z',
		},
		{
			title: 'Oud besluit',
			source: 'dossiq',
			state: 'published',
			publishedAt: '2026-09-02T00:00:00Z',
		},
	],
}

describe('obligationPage (REQ-WOO-003)', () => {
	it('shows the totals the board draws: outstanding, due this week, late, published this month and on time', () => {
		const page = obligationPage(overview, now)
		expect(page.tiles).toEqual({
			outstanding: 3,
			sources: 2,
			dueThisWeek: 1,
			late: 1,
			publishedThisMonth: 1,
			onTimeThisMonth: 1,
		})
	})

	it('lists the outstanding obligations late first, then by due date, and an unknown due date last', () => {
		const page = obligationPage(overview, now)
		expect(page.rows.map((row) => row.title)).toEqual([
			'Besluit jeugdzorg',
			'Besluitenlijst raad',
			'Convenant',
		])
		expect(page.rows[0].late).toBe(true)
		expect(page.rows[0].daysLate).toBe(2)
		expect(page.rows[2].dueDate).toBe(null)
	})

	it('groups per source with the next due date and the late count', () => {
		const page = obligationPage(overview, now)
		expect(page.perSource).toEqual([
			{
				source: 'dossiq',
				outstanding: 1,
				nextDue: '2026-10-06T00:00:00Z',
				late: 1,
			},
			{
				source: 'notubiz',
				outstanding: 2,
				nextDue: '2026-10-09T00:00:00Z',
				late: 0,
			},
		])
	})

	it('names every unread source with its reason, never as a source with nothing to publish', () => {
		const page = obligationPage(overview, now)
		expect(page.unreadSources).toEqual([
			{ appId: 'decos', reason: 'No reader answered for this source.' },
		])
		expect(page.perSource.map((row) => row.source)).not.toContain('decos')
	})

	it('reads an empty or missing answer as nothing, without throwing', () => {
		const page = obligationPage(null, now)
		expect(page.rows).toEqual([])
		expect(page.tiles.outstanding).toBe(0)
		expect(page.unreadSources).toEqual([])
	})
})
