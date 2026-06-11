/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helper services in src/services/. These are the
 * date-validation, publication-status-derivation and publication-type-id
 * extraction utilities the publication UI relies on. They take no DOM and
 * import no Nextcloud runtime, so they are exact-output assertable.
 */

import { describe, it, expect, vi, afterEach } from 'vitest'
import getValidISOstring from '../../src/services/getValidISOstring.js'
import { getPublicationTypeId } from '../../src/services/getPublicationTypeId.js'
import {
	isPublished,
	isDepublished,
	isConcept,
	getPublicationStatus,
} from '../../src/services/publicationStatus.js'

describe('getValidISOstring', () => {
	it('accepts well-formed ISO 8601 datetimes (with/without ms + tz)', () => {
		expect(getValidISOstring('2026-06-11T10:30:00Z')).toBe(true)
		expect(getValidISOstring('2026-06-11T10:30:00.123Z')).toBe(true)
		expect(getValidISOstring('2026-06-11T10:30:00+02:00')).toBe(true)
		expect(getValidISOstring('2026-06-11T10:30:00')).toBe(true)
	})

	it('rejects empty, non-string, date-only, and malformed input', () => {
		expect(getValidISOstring('')).toBe(false)
		expect(getValidISOstring(null)).toBe(false)
		expect(getValidISOstring(undefined)).toBe(false)
		expect(getValidISOstring(20260611)).toBe(false)
		expect(getValidISOstring('2026-06-11')).toBe(false) // date only, no time
		expect(getValidISOstring('not-a-date')).toBe(false)
	})

	it('rejects a structurally-valid-but-impossible date', () => {
		// matches the regex but Date() yields NaN
		expect(getValidISOstring('2026-13-40T99:99:99Z')).toBe(false)
	})
})

describe('getPublicationTypeId', () => {
	it('extracts the trailing path segment from a URL', () => {
		expect(getPublicationTypeId('https://example.org/api/publication_types/42')).toBe('42')
		expect(getPublicationTypeId('/local/path/abc-123')).toBe('abc-123')
	})

	it('returns the whole string when there is no slash', () => {
		expect(getPublicationTypeId('99')).toBe('99')
	})

	it('returns empty string for a trailing slash', () => {
		expect(getPublicationTypeId('https://example.org/types/')).toBe('')
	})
})

describe('publicationStatus', () => {
	// Freeze "now" so the date comparisons are deterministic.
	const NOW = new Date('2026-06-11T12:00:00Z')

	afterEach(() => {
		vi.useRealTimers()
	})

	function freezeNow() {
		vi.useFakeTimers()
		vi.setSystemTime(NOW)
	}

	const past = '2026-01-01T00:00:00Z'
	const future = '2026-12-31T00:00:00Z'

	it('isConcept: no publicatiedatum or a future one', () => {
		freezeNow()
		expect(isConcept({})).toBe(true)
		expect(isConcept({ publicatiedatum: future })).toBe(true)
		expect(isConcept({ publicatiedatum: past })).toBe(false)
	})

	it('isPublished: past publish date, no/future depublish', () => {
		freezeNow()
		expect(isPublished({ publicatiedatum: past })).toBe(true)
		expect(isPublished({ publicatiedatum: past, depublicatiedatum: future })).toBe(true)
		expect(isPublished({ publicatiedatum: past, depublicatiedatum: past })).toBe(false)
		expect(isPublished({ publicatiedatum: future })).toBe(false)
		expect(isPublished({})).toBe(false)
	})

	it('isDepublished: past publish AND past depublish', () => {
		freezeNow()
		expect(isDepublished({ publicatiedatum: past, depublicatiedatum: past })).toBe(true)
		expect(isDepublished({ publicatiedatum: past })).toBe(false)
		expect(isDepublished({ publicatiedatum: future, depublicatiedatum: past })).toBe(false)
	})

	it('getPublicationStatus: resolves the three states by priority', () => {
		freezeNow()
		expect(getPublicationStatus({})).toBe('concept')
		expect(getPublicationStatus({ publicatiedatum: future })).toBe('concept')
		expect(getPublicationStatus({ publicatiedatum: past })).toBe('published')
		expect(getPublicationStatus({ publicatiedatum: past, depublicatiedatum: past })).toBe('depublished')
	})

	it('treats an unparseable date as absent (concept, fail-safe)', () => {
		freezeNow()
		expect(getPublicationStatus({ publicatiedatum: 'garbage' })).toBe('concept')
	})
})
