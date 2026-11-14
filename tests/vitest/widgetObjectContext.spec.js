/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for how a detail-page widget finds its object
 * (src/components/widgets/objectContext.js): the injected context in its
 * ref and plain shapes, the page renderer's holder ref, and a schema as the
 * slug the cards want.
 */

import { describe, expect, it } from 'vitest'
import {
	injectedObjectContext,
	schemaSlug,
} from '../../src/components/widgets/objectContext.js'

describe('injectedObjectContext', () => {
	it('returns {} when nothing is injected', () => {
		expect(injectedObjectContext({ cnObjectContext: null, cnDetailObjectContext: null })).toEqual({})
	})

	it('unwraps a ref-shaped cnObjectContext to its value', () => {
		const ctx = { objectId: 'a' }
		expect(injectedObjectContext({ cnObjectContext: { value: ctx }, cnDetailObjectContext: null })).toBe(ctx)
	})

	it('uses a plain cnObjectContext as it is', () => {
		const ctx = { objectId: 'b', register: 'r' }
		expect(injectedObjectContext({ cnObjectContext: ctx, cnDetailObjectContext: null })).toBe(ctx)
	})

	it('prefers cnObjectContext over the detail holder', () => {
		const ctx = { objectId: 'c' }
		const holder = { value: { objectId: 'holder' } }
		expect(injectedObjectContext({ cnObjectContext: ctx, cnDetailObjectContext: holder })).toBe(ctx)
	})

	it('falls back to the detail holder value when cnObjectContext is absent', () => {
		const inner = { objectId: 'holder' }
		expect(injectedObjectContext({ cnObjectContext: null, cnDetailObjectContext: { value: inner } })).toBe(inner)
	})

	it('falls back to {} when the detail holder is an empty ref', () => {
		expect(injectedObjectContext({ cnObjectContext: null, cnDetailObjectContext: { value: null } })).toEqual({})
	})
})

describe('schemaSlug', () => {
	it('returns a string schema unchanged, including the empty string', () => {
		expect(schemaSlug('publication')).toBe('publication')
		expect(schemaSlug('')).toBe('')
	})

	it('reads an object by slug, then name, then id', () => {
		expect(schemaSlug({ slug: 's', name: 'n', id: 3 })).toBe('s')
		expect(schemaSlug({ name: 'n', id: 3 })).toBe('n')
		expect(schemaSlug({ id: 3 })).toBe(3)
	})

	it('returns an empty string for null, undefined or an object without identity', () => {
		expect(schemaSlug(null)).toBe('')
		expect(schemaSlug(undefined)).toBe('')
		expect(schemaSlug({})).toBe('')
	})
})
