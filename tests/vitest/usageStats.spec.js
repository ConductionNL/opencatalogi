/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure usage-analytics presentation helpers in
 * src/services/usageStats.js — trend derivation, count formatting, the
 * counting-start note and the export-URL builder. These take no DOM and assert
 * the dashboard/detail-panel logic exactly (Vitest offline suite).
 */
import { describe, it, expect } from 'vitest'
import {
	deriveTrend,
	formatCount,
	countingStartNote,
	catalogExportUrl,
} from '../../src/services/usageStats.js'

const t = (app, key, params = {}) =>
	key.replace(/\{(\w+)\}/g, (_, k) => (params[k] !== undefined ? params[k] : `{${k}}`))

describe('deriveTrend', () => {
	it('returns up when the recent half outweighs the older half', () => {
		const series = [
			{ date: '2026-05-01', views: 1 },
			{ date: '2026-05-02', views: 1 },
			{ date: '2026-05-03', views: 9 },
			{ date: '2026-05-04', views: 9 },
		]
		expect(deriveTrend(series, 'views')).toBe('up')
	})

	it('returns down when reach is falling', () => {
		const series = [
			{ date: '2026-05-01', views: 9 },
			{ date: '2026-05-02', views: 1 },
		]
		expect(deriveTrend(series, 'views')).toBe('down')
	})

	it('returns flat for empty / single-point / equal halves', () => {
		expect(deriveTrend([], 'views')).toBe('flat')
		expect(deriveTrend([{ date: 'x', views: 5 }], 'views')).toBe('flat')
		expect(deriveTrend([{ views: 2 }, { views: 2 }], 'views')).toBe('flat')
	})

	it('honours the downloads metric independently', () => {
		const series = [
			{ downloads: 0 },
			{ downloads: 0 },
			{ downloads: 4 },
			{ downloads: 4 },
		]
		expect(deriveTrend(series, 'downloads')).toBe('up')
	})
})

describe('formatCount', () => {
	it('formats numbers and coerces invalid input to 0', () => {
		expect(formatCount(0)).toBe((0).toLocaleString())
		expect(formatCount(1234)).toBe((1234).toLocaleString())
		expect(formatCount(undefined)).toBe((0).toLocaleString())
		expect(formatCount('nope')).toBe((0).toLocaleString())
	})
})

describe('countingStartNote', () => {
	it('reports no measurement when counting has not started', () => {
		expect(countingStartNote(null, t)).toBe('No usage has been measured yet.')
	})

	it('qualifies the counting-start date so officers do not misread early numbers', () => {
		expect(countingStartNote('2026-05-01', t)).toContain('2026-05-01')
	})
})

describe('catalogExportUrl', () => {
	it('builds a bare export URL with no params', () => {
		expect(catalogExportUrl('woo')).toContain('/stats/export')
	})

	it('appends from/to query params when provided', () => {
		const url = catalogExportUrl('woo', { from: '2026-01-01', to: '2026-12-31' })
		expect(url).toContain('from=2026-01-01')
		expect(url).toContain('to=2026-12-31')
	})
})
