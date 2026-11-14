/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the publication merge and migration handlers, the catalog
 * count and feature rows, and the retention review and expiry checks
 * (Vitest offline suite).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { catalogCountRows, catalogFeatureRows } from '../../src/services/catalogMetadata.js'
import { createPublicationActionHandlers } from '../../src/services/publicationActions.js'
import { isExpiringSoon, isReviewRequired } from '../../src/services/retentionStatus.js'

function stores() {
	return {
		objectStore: { setSelectedObjects: vi.fn(), setActiveObject: vi.fn() },
		navigationStore: { setModal: vi.fn(), setDialog: vi.fn(), setTransferData: vi.fn() },
	}
}

describe('publication merge', () => {
	it('opens the merge modal at step 2 with the first as source and the second as target', () => {
		const s = stores()
		const a = { id: 'a' }
		const b = { id: 'b' }

		expect(createPublicationActionHandlers(s).openPublicationMerge({ objects: [a, b] })).toBe(true)
		expect(s.navigationStore.setTransferData).toHaveBeenCalledWith({ mergeSource: a, mergeTarget: b, startStep: 2 })
		expect(s.navigationStore.setModal).toHaveBeenCalledWith('mergeObject')
	})

	it('refuses any other number of publications with a translated error', () => {
		const s = stores()
		const showError = vi.fn()
		const t = vi.fn((app, text) => `[${app}] ${text}`)
		const handlers = createPublicationActionHandlers({ ...s, showError, t })

		expect(handlers.openPublicationMerge({ objects: [{ id: 'a' }] })).toBe(false)
		expect(handlers.openPublicationMerge({})).toBe(false)
		expect(showError).toHaveBeenCalledWith('[opencatalogi] Select exactly two publications to merge')
		expect(showError).toHaveBeenCalledTimes(2)
		expect(s.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('works with the default error reporter', () => {
		const s = stores()
		expect(createPublicationActionHandlers(s).openPublicationMerge({ objects: [] })).toBe(false)
	})
})

describe('publication migration', () => {
	it('selects a copy of the publications and opens the migration modal', () => {
		const s = stores()
		const objects = [{ id: 1 }, { id: 2 }, { id: 3 }]

		expect(createPublicationActionHandlers(s).openPublicationMigration({ objects })).toBe(true)
		expect(s.objectStore.setSelectedObjects).toHaveBeenCalledWith(objects)
		expect(s.objectStore.setSelectedObjects.mock.calls[0][0]).not.toBe(objects)
		expect(s.navigationStore.setModal).toHaveBeenCalledWith('migrationObject')
	})

	it('opens nothing without a selection', () => {
		const s = stores()

		expect(createPublicationActionHandlers(s).openPublicationMigration({})).toBe(false)
		expect(s.objectStore.setSelectedObjects).not.toHaveBeenCalled()
		expect(s.navigationStore.setModal).not.toHaveBeenCalled()
	})
})

describe('catalog rows', () => {
	it('counts registers and schemas, zero when absent', () => {
		expect(catalogCountRows({ registers: [1, 2], schemas: [3] })).toEqual([
			{ label: 'Registers', value: 2 },
			{ label: 'Schemas', value: 1 },
		])
		expect(catalogCountRows({})).toEqual([
			{ label: 'Registers', value: 0 },
			{ label: 'Schemas', value: 0 },
		])
	})

	it('says yes or no per feature', () => {
		expect(catalogFeatureRows({ hasWooSitemap: true, hasOoapi: false })).toEqual([
			{ label: 'WOO Sitemap', value: 'Yes' },
			{ label: 'OOAPI 5.0 Publication', value: 'No' },
		])
	})
})

describe('retention checks', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	const day = 24 * 60 * 60 * 1000

	function at(now) {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(now))
	}

	it('an archived publication is neither expiring nor up for review', () => {
		const archived = { status: 'archived', retentionExpiresAt: '2000-01-01T00:00:00Z' }
		expect(isExpiringSoon(archived)).toBe(false)
		expect(isReviewRequired(archived)).toBe(false)
	})

	it('without an expiry nothing is due', () => {
		expect(isExpiringSoon({})).toBe(false)
		expect(isReviewRequired({ retentionExpiresAt: 'not a date' })).toBe(false)
	})

	it('expires soon only inside the window and before expiry', () => {
		at('2026-06-01T00:00:00Z')
		const now = Date.parse('2026-06-01T00:00:00Z')
		expect(isExpiringSoon({ retentionExpiresAt: new Date(now + 10 * day).toISOString() })).toBe(true)
		expect(isExpiringSoon({ retentionExpiresAt: new Date(now + 40 * day).toISOString() })).toBe(false)
		expect(isExpiringSoon({ retentionExpiresAt: new Date(now + 40 * day).toISOString() }, 60)).toBe(true)
		expect(isExpiringSoon({ retentionExpiresAt: new Date(now - day).toISOString() })).toBe(false)
	})

	it('needs review once expired, when the action is review or unset', () => {
		at('2026-06-01T00:00:00Z')
		const expired = '2026-05-01T00:00:00Z'
		expect(isReviewRequired({ retentionExpiresAt: expired })).toBe(true)
		expect(isReviewRequired({ retentionExpiresAt: expired, retentionAction: 'review' })).toBe(true)
		expect(isReviewRequired({ retentionExpiresAt: expired, retentionAction: 'archive' })).toBe(false)
		expect(isReviewRequired({ retentionExpiresAt: '2026-07-01T00:00:00Z' })).toBe(false)
	})
})
