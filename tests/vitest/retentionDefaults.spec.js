/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */
import { describe, expect, it } from 'vitest'
import { catalogRows, FALLBACK, termIsValid, withCatalogRows } from '../../src/services/retentionDefaults.js'

const categories = [
	{ code: 'infocat001', label: 'Wetten en algemeen verbindende voorschriften' },
	{ code: 'infocat012', label: 'Vergunningen' },
]

describe('catalogRows', () => {
	it('lists the fallback first and then each category with its stored rule', () => {
		const defaults = {
			woo: { infocat012: { termMonths: 12, action: 'depublish' }, _fallback: { termMonths: 120, action: 'archive' } },
			other: { infocat001: { termMonths: 6 } },
		}

		expect(catalogRows(defaults, 'woo', categories)).toEqual([
			{ code: FALLBACK, label: '', termMonths: '120', action: 'archive' },
			{ code: 'infocat001', label: 'Wetten en algemeen verbindende voorschriften', termMonths: '', action: '' },
			{ code: 'infocat012', label: 'Vergunningen', termMonths: '12', action: 'depublish' },
		])
	})

	it('shows an unusable stored term or action as empty', () => {
		const rows = catalogRows({ woo: { infocat001: { termMonths: -3, action: 'shred' } } }, 'woo', categories)

		expect(rows[1]).toEqual({ code: 'infocat001', label: categories[0].label, termMonths: '', action: '' })
	})

	it('gives empty rows for a catalogue without defaults or a broken map', () => {
		for (const defaults of [{}, null, { woo: 'x' }]) {
			expect(catalogRows(defaults, 'woo', categories).map((row) => row.termMonths + row.action)).toEqual(['', '', ''])
		}
	})
})

describe('termIsValid', () => {
	it('accepts empty and positive whole months only', () => {
		expect(['', ' ', '1', '120'].map((termMonths) => termIsValid({ termMonths }))).toEqual([true, true, true, true])
		expect(['0', '-1', '1.5', 'twelve', '012'].map((termMonths) => termIsValid({ termMonths }))).toEqual([false, false, false, false, false])
	})
})

describe('withCatalogRows', () => {
	it('replaces only this catalogue and drops empty rows', () => {
		const defaults = { woo: { infocat001: { termMonths: 6 } }, other: { _fallback: { action: 'review' } } }
		const rows = [
			{ code: FALLBACK, termMonths: '', action: 'review' },
			{ code: 'infocat001', termMonths: '', action: '' },
			{ code: 'infocat012', termMonths: ' 24 ', action: 'archive' },
		]

		expect(withCatalogRows(defaults, 'woo', rows)).toEqual({
			other: { _fallback: { action: 'review' } },
			woo: { _fallback: { action: 'review' }, infocat012: { termMonths: 24, action: 'archive' } },
		})
		expect(defaults.woo).toEqual({ infocat001: { termMonths: 6 } })
	})

	it('removes the catalogue entry when every row is empty', () => {
		const rows = [{ code: FALLBACK, termMonths: '', action: '' }]

		expect(withCatalogRows({ woo: { _fallback: { termMonths: 1 } }, other: {} }, 'woo', rows)).toEqual({ other: {} })
	})

	it('ignores an action the server does not know', () => {
		expect(withCatalogRows(null, 'woo', [{ code: 'infocat001', termMonths: '3', action: 'shred' }])).toEqual({
			woo: { infocat001: { termMonths: 3 } },
		})
	})
})
