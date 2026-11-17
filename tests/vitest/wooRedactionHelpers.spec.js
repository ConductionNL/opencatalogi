/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the redaction page helpers in src/services/wooHelpers.js
 * (woo-document-assessment-screen, REQ-WDA-003): the findings as numbered rows
 * with kept and rejected counts, and the three redaction steps.
 */
import { describe, expect, it } from 'vitest'
import { redactionRows, redactionSteps } from '../../src/services/wooHelpers.js'

describe('redactionRows', () => {
	it('numbers the findings by page and position and counts kept and rejected', () => {
		const { rows, kept, rejected } = redactionRows([
			{
				id: 3,
				entityId: 30,
				page: 2,
				positionStart: 5,
				skipAnonymization: false,
				bases: ['5.1.2e'],
				anonymizedValue: '[NAAM]',
			},
			{
				id: 1,
				entityId: 10,
				page: 1,
				positionStart: 40,
				skipAnonymization: true,
				bases: null,
				role: 'person',
			},
			{
				id: 2,
				entityId: 20,
				page: 1,
				positionStart: 4,
				skipAnonymization: false,
				bases: ['5.1.2e', '5.2'],
				anonymizedValue: '[EMAIL]',
			},
			{
				id: 4,
				entityId: 40,
				page: 3,
				positionStart: 0,
				skipAnonymization: null,
				bases: null,
			},
		])
		expect(rows.map((r) => [r.number, r.id, r.state])).toEqual([
			[1, 2, 'kept'],
			[2, 1, 'rejected'],
			[3, 3, 'kept'],
			[4, 4, 'hidden'],
		])
		expect(rows[0]).toEqual({
			number: 1,
			id: 2,
			entityId: 20,
			what: '[EMAIL]',
			page: 1,
			grounds: ['5.1.2e', '5.2'],
			state: 'kept',
		})
		expect(rows[1].what).toBe('person')
		expect(rows[1].grounds).toEqual([])
		expect(kept).toBe(2)
		expect(rejected).toBe(1)
	})

	it('gives no rows and zero counts for no relations', () => {
		expect(redactionRows(null)).toEqual({ rows: [], kept: 0, rejected: 0 })
	})
})

describe('redactionSteps', () => {
	it('marks every step done for a verified redaction a person checked', () => {
		expect(
			redactionSteps({
				documentReference: '11',
				anonymizedDocument: '12',
				redactionStatus: 'verified',
				assessedAt: '2026-11-14T09:00:00Z',
				redactionVerifiedBy: 'anne',
				redactionVerifiedAt: '2026-11-14T10:00:00Z',
			}),
		).toEqual([
			{ key: 'original', done: true, by: '', at: '' },
			{ key: 'generated', done: true, by: '', at: '2026-11-14T09:00:00Z' },
			{ key: 'verified', done: true, by: 'anne', at: '2026-11-14T10:00:00Z' },
		])
	})

	it('leaves generated and verified open while the redaction failed', () => {
		const steps = redactionSteps({
			documentReference: '11',
			anonymizedDocument: '',
			redactionStatus: 'failed',
			assessedAt: '2026-11-14T09:00:00Z',
		})
		expect(steps.map((s) => s.done)).toEqual([true, false, false])
		expect(steps[1].at).toBe('')
		expect(steps[2].by).toBe('')
	})
})
