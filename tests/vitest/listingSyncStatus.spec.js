/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/listingSyncStatus.js: what the Directory page
 * shows per listing about its last successful sync, its last attempt and the
 * error of a failed attempt (REQ-FLS-002). Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import { listingSyncStatus } from '../../src/services/listingSyncStatus.js'

describe('listingSyncStatus (REQ-FLS-002)', () => {
	it('a failing peer keeps its last success and shows the failed attempt with its error', () => {
		const status = listingSyncStatus({
			lastSync: '2026-10-09T08:00:00+00:00',
			lastSuccessAt: '2026-10-07T08:00:00+00:00',
			lastError: 'cURL error 28: Operation timed out',
		})
		expect(status.lastSuccessAt).toBe('2026-10-07T08:00:00+00:00')
		expect(status.lastAttemptAt).toBe('2026-10-09T08:00:00+00:00')
		expect(status.error).toBe('cURL error 28: Operation timed out')
		expect(status.neverSucceeded).toBe(false)
	})

	it('a new peer whose first sync failed never synchronised successfully', () => {
		const status = listingSyncStatus({
			lastSync: '2026-10-09T08:00:00+00:00',
			lastError: 'Could not resolve host',
		})
		expect(status.neverSucceeded).toBe(true)
		expect(status.lastSuccessAt).toBe(null)
		expect(status.lastAttemptAt).toBe('2026-10-09T08:00:00+00:00')
		expect(status.error).toBe('Could not resolve host')
	})

	it('a healthy peer shows no separate attempt and no error', () => {
		const status = listingSyncStatus({
			lastSync: '2026-10-09T08:00:00+00:00',
			lastSuccessAt: '2026-10-09T08:00:00+00:00',
			lastError: '',
		})
		expect(status.lastAttemptAt).toBe(null)
		expect(status.error).toBe('')
		expect(status.neverSucceeded).toBe(false)
	})

	it('a stale error from before the last success is not shown', () => {
		const status = listingSyncStatus({
			lastSync: '2026-10-09T08:00:00+00:00',
			lastSuccessAt: '2026-10-09T08:00:00+00:00',
			lastError: 'old failure',
		})
		expect(status.error).toBe('')
	})

	it('a listing that was never tried has no attempt and never succeeded', () => {
		const status = listingSyncStatus({})
		expect(status.neverSucceeded).toBe(true)
		expect(status.lastAttemptAt).toBe(null)
		expect(status.error).toBe('')
	})

	it('tolerates a missing listing', () => {
		expect(listingSyncStatus(null).neverSucceeded).toBe(true)
	})
})
