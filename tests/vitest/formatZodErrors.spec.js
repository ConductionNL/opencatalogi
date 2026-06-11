/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for createZodErrorHandler in src/services/formatZodErrors.js.
 * This is the form-validation error mapper the generic object modals use to
 * surface per-field messages; it is pure (no DOM, no NC runtime) and its
 * grouped/nested/summary projections are exactly assertable.
 */

import { describe, it, expect } from 'vitest'
import { createZodErrorHandler } from '../../src/services/formatZodErrors.js'

// A representative Zod-style failure result with three issues across two paths.
const failResult = {
	success: false,
	error: {
		issues: [
			{ path: ['items', 0, 'name'], message: 'naam is verplicht', code: 'too_small', type: 'string' },
			{ path: ['items', 0, 'summary'], message: 'summary is verplicht', code: 'too_small', type: 'string' },
			{ path: ['items', 0, 'name'], message: 'naam te kort', code: 'too_small', type: 'string' },
		],
	},
}

const okResult = { success: true }

describe('createZodErrorHandler — getError / getErrors', () => {
	it('getError returns the FIRST message for a path (string or array form)', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.getError('items.0.name')).toBe('naam is verplicht')
		expect(h.getError(['items', 0, 'name'])).toBe('naam is verplicht')
	})

	it('getError returns undefined for a path without an issue', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.getError('items.0.link')).toBeUndefined()
	})

	it('getErrors returns ALL messages for a path', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.getErrors('items.0.name')).toEqual(['naam is verplicht', 'naam te kort'])
		expect(h.getErrors('items.0.summary')).toEqual(['summary is verplicht'])
		expect(h.getErrors('items.0.missing')).toEqual([])
	})
})

describe('createZodErrorHandler — projections', () => {
	it('flatErrorMessages joins path + message', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.flatErrorMessages).toEqual([
			'items.0.name: naam is verplicht',
			'items.0.summary: summary is verplicht',
			'items.0.name: naam te kort',
		])
	})

	it('groupedErrorsByPath buckets messages under their dotted path', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.groupedErrorsByPath).toEqual({
			'items.0.name': ['naam is verplicht', 'naam te kort'],
			'items.0.summary': ['summary is verplicht'],
		})
	})

	it('nestedFieldErrors builds the nested object tree', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.nestedFieldErrors).toEqual({
			items: {
				0: {
					name: ['naam is verplicht', 'naam te kort'],
					summary: ['summary is verplicht'],
				},
			},
		})
	})

	it('fieldSpecificErrors carries path/message/code/type', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.fieldSpecificErrors[0]).toEqual({
			path: 'items.0.name',
			message: 'naam is verplicht',
			code: 'too_small',
			type: 'string',
		})
		expect(h.fieldSpecificErrors).toHaveLength(3)
	})

	it('errorSummary counts totals, by-field and by-type', () => {
		const h = createZodErrorHandler(failResult)
		expect(h.errorSummary).toEqual({
			totalErrors: 3,
			errorsByField: {
				'items.0.name': 2,
				'items.0.summary': 1,
			},
			errorsByType: {
				too_small: 3,
			},
		})
	})
})

describe('createZodErrorHandler — success path', () => {
	it('exposes empty projections and success=true when there is no error', () => {
		const h = createZodErrorHandler(okResult)
		expect(h.success).toBe(true)
		expect(h.flatErrorMessages).toEqual([])
		expect(h.groupedErrorsByPath).toEqual({})
		expect(h.nestedFieldErrors).toEqual({})
		expect(h.fieldSpecificErrors).toEqual([])
		expect(h.errorSummary).toEqual({})
		expect(h.getError('anything')).toBeUndefined()
		expect(h.getErrors('anything')).toEqual([])
	})
})
