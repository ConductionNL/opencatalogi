/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for formValue, the value resolution behind ViewObject's
 * required-field and validation computeds (#2370).
 */

import { describe, expect, it } from 'vitest'
import { computed, reactive } from 'vue'
import { formValue } from '../../src/services/formValue.js'

describe('formValue', () => {
	it('prefers the form value over the persisted value', () => {
		expect(formValue({ title: 'New' }, { title: 'Old' }, 'title')).toBe('New')
	})

	it('falls back to the persisted value when the form lacks the key', () => {
		expect(formValue({}, { title: 'Old' }, 'title')).toBe('Old')
	})

	it('keeps an explicitly emptied form value', () => {
		expect(formValue({ title: '' }, { title: 'Old' }, 'title')).toBe('')
	})

	it('returns undefined without a persisted object', () => {
		expect(formValue({}, null, 'title')).toBeUndefined()
	})

	it('ignores prototype keys on the form', () => {
		expect(formValue({}, { constructor: 'x' }, 'constructor')).toBe('x')
	})

	it('re-runs a computed when a key it lacked is added to the form', () => {
		const formData = reactive({})
		const missing = computed(() => !formValue(formData, null, 'title'))

		expect(missing.value).toBe(true)
		formData.title = 'Typed title'
		expect(missing.value).toBe(false)
	})
})
