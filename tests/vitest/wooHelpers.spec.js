/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure WOO client helpers in src/services/wooHelpers.js:
 * per-status summary/progress derivation and the ready-for-review gate.
 * Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	canMarkReadyForReview,
	deriveSummary,
} from '../../src/services/wooHelpers.js'

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
		expect(
			canMarkReadyForReview(
				deriveSummary([
					{ assessment: 'openbaar' },
					{ assessment: 'niet_openbaar' },
				]),
			),
		).toBe(true)
	})
	it('false when something is still te_beoordelen', () => {
		expect(
			canMarkReadyForReview(
				deriveSummary([
					{ assessment: 'openbaar' },
					{ assessment: 'te_beoordelen' },
				]),
			),
		).toBe(false)
	})
	it('false for an empty batch', () => {
		expect(canMarkReadyForReview(deriveSummary([]))).toBe(false)
		expect(canMarkReadyForReview(null)).toBe(false)
	})
})
