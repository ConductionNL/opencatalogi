/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the document queue helpers in src/services/wooHelpers.js
 * (woo-document-assessment-screen): grouping into columns, which moves ask for
 * grounds, and the redaction state of a card.
 */
import { describe, expect, it } from 'vitest'
import {
	formatSize,
	groupByAssessment,
	needsGrounds,
	redactionState,
	replaceAssessment,
} from '../../src/services/wooHelpers.js'

describe('groupByAssessment', () => {
	it('puts every assessment in its column and unknown ones in to assess', () => {
		const columns = groupByAssessment([
			{ id: 'a', assessment: 'openbaar' },
			{ id: 'b', assessment: 'deels_openbaar' },
			{ id: 'c' },
			{ id: 'd', assessment: 'bogus' },
			{ id: 'e', assessment: 'niet_openbaar' },
		])
		expect(Object.keys(columns)).toEqual([
			'te_beoordelen',
			'openbaar',
			'deels_openbaar',
			'niet_openbaar',
		])
		expect(columns.te_beoordelen.map((i) => i.id)).toEqual(['c', 'd'])
		expect(columns.openbaar.map((i) => i.id)).toEqual(['a'])
		expect(columns.deels_openbaar.map((i) => i.id)).toEqual(['b'])
		expect(columns.niet_openbaar.map((i) => i.id)).toEqual(['e'])
	})

	it('gives four empty columns for no assessments', () => {
		expect(groupByAssessment(null)).toEqual({
			te_beoordelen: [],
			openbaar: [],
			deels_openbaar: [],
			niet_openbaar: [],
		})
	})
})

describe('needsGrounds', () => {
	it('asks grounds for partly and not public only', () => {
		expect(needsGrounds('deels_openbaar')).toBe(true)
		expect(needsGrounds('niet_openbaar')).toBe(true)
		expect(needsGrounds('openbaar')).toBe(false)
		expect(needsGrounds('te_beoordelen')).toBe(false)
	})
})

describe('replaceAssessment', () => {
	it('merges the saved assessment into its row and keeps the order', () => {
		const list = [
			{ id: 'a', assessment: 'te_beoordelen', fileName: 'a.pdf' },
			{ id: 'b', assessment: 'openbaar' },
		]
		expect(
			replaceAssessment(list, { id: 'a', assessment: 'deels_openbaar' }),
		).toEqual([
			{ id: 'a', assessment: 'deels_openbaar', fileName: 'a.pdf' },
			{ id: 'b', assessment: 'openbaar' },
		])
	})
})

describe('redactionState', () => {
	it('reads the redaction of a partly public card', () => {
		expect(redactionState({ assessment: 'openbaar' })).toBeNull()
		expect(
			redactionState({
				assessment: 'deels_openbaar',
				redactionStatus: 'failed',
			}),
		).toBe('failed')
		expect(redactionState({ assessment: 'deels_openbaar' })).toBe('pending')
		expect(
			redactionState({
				assessment: 'deels_openbaar',
				redactionStatus: 'verified',
			}),
		).toBe('unchecked')
		expect(
			redactionState({
				assessment: 'deels_openbaar',
				redactionStatus: 'verified',
				redactionVerifiedBy: 'alice',
			}),
		).toBe('checked')
	})
})

describe('formatSize', () => {
	it('formats bytes and hides an unknown size', () => {
		expect(formatSize(512)).toBe('512 B')
		expect(formatSize(2048)).toBe('2.0 KB')
		expect(formatSize(5 * 1024 * 1024)).toBe('5.0 MB')
		expect(formatSize(0)).toBe('')
		expect(formatSize('x')).toBe('')
		expect(formatSize(undefined)).toBe('')
	})
})
