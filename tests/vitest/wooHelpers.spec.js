/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure WOO client helpers in src/services/wooHelpers.js —
 * redaction-instruction building (entity -> weigeringsgrond mapping), pages-with-
 * entities derivation, per-status summary/progress derivation, and the ready-for-
 * review gate. Offline, no DOM.
 */
import { describe, it, expect } from 'vitest'
import {
	buildRedactionInstructions,
	pagesWithEntities,
	deriveSummary,
	canMarkReadyForReview,
} from '../../src/services/wooHelpers.js'

describe('buildRedactionInstructions', () => {
	const entities = [
		{ id: 'e1', text: 'Jan', page: 1 },
		{ id: 'e2', text: 'BSN', page: 2 },
		{ id: 'e3', text: 'Adres', page: 2 },
	]

	it('only includes selected entities and maps the chosen ground', () => {
		const selected = { e1: true, e2: false, e3: true }
		const grounds = { e1: { id: '5.1.2.e' }, e3: { id: '5.2.e' } }
		const out = buildRedactionInstructions(entities, selected, grounds)
		expect(out).toHaveLength(2)
		expect(out[0]).toEqual({ entityId: 'e1', text: 'Jan', page: 1, weigeringsgrond: '5.1.2.e' })
		expect(out[1].weigeringsgrond).toBe('5.2.e')
	})

	it('uses null when no ground was chosen', () => {
		const out = buildRedactionInstructions(entities, { e1: true }, {})
		expect(out[0].weigeringsgrond).toBeNull()
	})

	it('handles empty input gracefully', () => {
		expect(buildRedactionInstructions(null, null, null)).toEqual([])
	})
})

describe('pagesWithEntities', () => {
	it('returns sorted unique pages', () => {
		const entities = [{ page: 3 }, { page: 1 }, { page: 3 }, { page: null }]
		expect(pagesWithEntities(entities)).toEqual([1, 3])
	})
	it('handles empty input', () => {
		expect(pagesWithEntities()).toEqual([])
	})
})

describe('deriveSummary', () => {
	it('counts per status and derives progress', () => {
		const assessments = [
			{ assessment: 'openbaar' },
			{ assessment: 'openbaar' },
			{ assessment: 'deels_openbaar' },
			{ assessment: 'niet_openbaar' },
			{ assessment: 'te_beoordelen' },
		]
		const summary = deriveSummary(assessments)
		expect(summary.total).toBe(5)
		expect(summary.assessed).toBe(4)
		expect(summary.progressLabel).toBe('4/5')
		expect(summary.counts.openbaar).toBe(2)
		expect(summary.counts.te_beoordelen).toBe(1)
	})
	it('ignores unknown statuses and handles empty', () => {
		const summary = deriveSummary([{ assessment: 'bogus' }])
		expect(summary.total).toBe(0)
		expect(deriveSummary().progressLabel).toBe('0/0')
	})
})

describe('canMarkReadyForReview', () => {
	it('true only when every document is assessed', () => {
		expect(canMarkReadyForReview(deriveSummary([
			{ assessment: 'openbaar' },
			{ assessment: 'niet_openbaar' },
		]))).toBe(true)
	})
	it('false when something is still te_beoordelen', () => {
		expect(canMarkReadyForReview(deriveSummary([
			{ assessment: 'openbaar' },
			{ assessment: 'te_beoordelen' },
		]))).toBe(false)
	})
	it('false for an empty batch', () => {
		expect(canMarkReadyForReview(deriveSummary([]))).toBe(false)
		expect(canMarkReadyForReview(null)).toBe(false)
	})
})
