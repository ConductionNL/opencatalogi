/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure retention-status helpers in
 * src/services/retentionStatus.js — expiry computation (RET-003), expiring-soon
 * / review-required / archived classification (RET-005/006/007). Offline, no DOM.
 */
import { describe, it, expect } from 'vitest'
import {
	computeExpiry,
	isArchived,
	isExpiringSoon,
	isReviewRequired,
	getRetentionStatus,
} from '../../src/services/retentionStatus.js'

const daysFromNow = (n) => new Date(Date.now() + n * 24 * 60 * 60 * 1000).toISOString()

describe('computeExpiry', () => {
	it('adds the term in months to the publication date', () => {
		expect(computeExpiry('2026-06-11T00:00:00Z', 12).slice(0, 7)).toBe('2027-06')
	})
	it('returns null when not computable', () => {
		expect(computeExpiry(null, 12)).toBeNull()
		expect(computeExpiry('2026-06-11', 0)).toBeNull()
	})
})

describe('isArchived', () => {
	it('reflects the archived lifecycle state', () => {
		expect(isArchived({ status: 'archived' })).toBe(true)
		expect(isArchived({ status: 'published' })).toBe(false)
	})
})

describe('isExpiringSoon', () => {
	it('flags an object expiring within the window', () => {
		expect(isExpiringSoon({ retentionExpiresAt: daysFromNow(20) }, 30)).toBe(true)
	})
	it('does not flag one expiring beyond the window', () => {
		expect(isExpiringSoon({ retentionExpiresAt: daysFromNow(40) }, 30)).toBe(false)
	})
	it('does not flag an already-expired object as expiring-soon', () => {
		expect(isExpiringSoon({ retentionExpiresAt: daysFromNow(-1) }, 30)).toBe(false)
	})
	it('never flags an archived object', () => {
		expect(isExpiringSoon({ status: 'archived', retentionExpiresAt: daysFromNow(10) }, 30)).toBe(false)
	})
})

describe('isReviewRequired', () => {
	it('flags an expired review-action object', () => {
		expect(isReviewRequired({ retentionExpiresAt: daysFromNow(-1), retentionAction: 'review' })).toBe(true)
	})
	it('does not flag an expired depublish-action object (auto-handled)', () => {
		expect(isReviewRequired({ retentionExpiresAt: daysFromNow(-1), retentionAction: 'depublish' })).toBe(false)
	})
})

describe('getRetentionStatus', () => {
	it('prioritises archived, then review-required, then expiring-soon', () => {
		expect(getRetentionStatus({ status: 'archived' })).toBe('archived')
		expect(getRetentionStatus({ retentionExpiresAt: daysFromNow(-1), retentionAction: 'review' })).toBe('review-required')
		expect(getRetentionStatus({ retentionExpiresAt: daysFromNow(10) }, 30)).toBe('expiring-soon')
		expect(getRetentionStatus({ retentionExpiresAt: daysFromNow(90) }, 30)).toBe('ok')
	})
})
