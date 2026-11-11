/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shape test for createZodErrorHandler: the handler's keys in order, with and
 * without an error, and a result without `error.issues`.
 */

import { describe, expect, it } from 'vitest'
import { createZodErrorHandler } from '../../src/services/formatZodErrors.js'

const KEYS = [
	'getError',
	'getErrors',
	'success',
	'flatErrorMessages',
	'groupedErrorsByPath',
	'nestedFieldErrors',
	'fieldSpecificErrors',
	'errorSummary',
]

describe('createZodErrorHandler shape', () => {
	it('keeps its keys in order without an error', () => {
		const handler = createZodErrorHandler({ success: true })

		expect(Object.keys(handler)).toEqual(KEYS)
		expect(handler.flatErrorMessages).toEqual([])
		expect(handler.groupedErrorsByPath).toEqual({})
		expect(handler.nestedFieldErrors).toEqual({})
		expect(handler.fieldSpecificErrors).toEqual([])
		expect(handler.errorSummary).toEqual({})
		expect(handler.getErrors('a')).toEqual([])
	})

	it('keeps its keys in order with an error', () => {
		const handler = createZodErrorHandler({
			success: false,
			error: { issues: [{ path: ['a'], message: 'm', code: 'c' }] },
		})

		expect(Object.keys(handler)).toEqual(KEYS)
		expect(handler.success).toBe(false)
		expect(handler.errorSummary.totalErrors).toBe(1)
	})

	it('throws for an error without issues, like before', () => {
		expect(() => createZodErrorHandler({ success: false, error: {} })).toThrow(TypeError)
	})
})
