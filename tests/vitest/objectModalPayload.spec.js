/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for objectPayload: the form cleaning, schema fill values, the
 * save payload, the empty check, the per-field validation and when a
 * publish action is offered (Vitest offline suite).
 */
import { describe, expect, it, vi } from 'vitest'
import {
	buildObjectPayload,
	cleanFormValues,
	isEmptyValue,
	offersPublishAction,
	propertyErrors,
	withSchemaFillValues,
} from '../../src/modals/object/objectPayload.js'

describe('cleanFormValues', () => {
	it('keeps property keys and drops numeric, private and $ keys', () => {
		expect(cleanFormValues({ title: 'T', 0: 'x', 12: 'y', _tmp: 1, $meta: 2, ok_too: 3 })).toEqual({ title: 'T', ok_too: 3 })
	})

	it('is empty for an array or nothing', () => {
		expect(cleanFormValues(['a'])).toEqual({})
		expect(cleanFormValues(null)).toEqual({})
	})
})

describe('withSchemaFillValues', () => {
	it('fills each missing property by its type and keeps what is there', () => {
		const object = { kept: 'x' }
		const result = withSchemaFillValues(object, {
			kept: { type: 'string' },
			text: { type: 'string' },
			fixed: { type: 'string', const: 'C' },
			num: { type: 'number' },
			int: { type: 'integer' },
			flag: { type: 'boolean' },
			list: { type: 'array' },
			obj: { type: 'object' },
			other: { type: 'file' },
		})

		expect(result).toBe(object)
		expect(result).toEqual({ kept: 'x', text: '', fixed: 'C', num: 0, int: 0, flag: false, list: [], obj: {}, other: '' })
	})

	it('gives every object fill its own instance', () => {
		const a = withSchemaFillValues({}, { list: { type: 'array' } })
		const b = withSchemaFillValues({}, { list: { type: 'array' } })
		expect(a.list).not.toBe(b.list)
	})
})

describe('buildObjectPayload', () => {
	const schemaProperties = {
		title: { type: 'string' },
		tags: { type: 'array' },
		kind: { type: 'string', const: 'K' },
		count: { type: 'integer' },
	}

	it('takes edited values, keeps current ones, fills the missing and drops id', () => {
		const payload = buildObjectPayload(
			{ id: 'x', title: 'Old', count: 3, '@self': { id: 'x' } },
			{ schemaProperties, form: { title: 'New' } },
		)

		expect(payload).toEqual({ title: 'New', count: 3, '@self': { id: 'x' }, tags: null, kind: 'K' })
	})

	it('normalises an edited array value', () => {
		const build = (tags) => buildObjectPayload({}, { schemaProperties: { tags: { type: 'array' } }, form: { tags } }).tags

		expect(build(['a'])).toEqual(['a'])
		expect(build('a, b ,c,,')).toEqual(['a', 'b', 'c'])
		expect(build(null)).toBeNull()
		expect(build(false)).toEqual([])
		expect(build(5)).toEqual(['5'])
		expect(build('')).toEqual([])
	})

	it('leaves an explicitly undefined edit out so the backend defaults it', () => {
		const payload = buildObjectPayload({ title: 'Old' }, { schemaProperties, form: { title: undefined } })

		expect(Object.hasOwn(payload, 'title')).toBe(false)
	})

	it('adds extra form keys that are not numeric or undefined', () => {
		const payload = buildObjectPayload(null, { schemaProperties: {}, form: { extra: 'e', 3: 'no', gone: undefined } })

		expect(payload).toEqual({ extra: 'e' })
	})
})

describe('isEmptyValue', () => {
	it('treats blanks and empty containers as empty', () => {
		for (const value of [null, undefined, '', {}, []]) {
			expect(isEmptyValue(value)).toBe(true)
		}
	})

	it('treats anything else as filled', () => {
		for (const value of [0, false, 'x', { a: 1 }, [1]]) {
			expect(isEmptyValue(value)).toBe(false)
		}
	})
})

describe('propertyErrors', () => {
	it('validates filled, visible properties and labels errors by title or key', () => {
		const values = { title: 'x', count: 'abc', hidden: 'h', blank: '', '@self': 's', id: 'i' }
		const validate = vi.fn((value) => (value === 'abc' || value === 'x' ? 'bad' : null))

		const errors = propertyErrors(
			{
				title: { title: 'Titel' },
				count: {},
				hidden: { hideOnForm: true },
				blank: {},
				'@self': {},
				id: {},
				none: null,
			},
			{ valueOf: (key) => values[key], validate },
		)

		expect(errors).toEqual([
			{ key: 'title', label: 'Titel', error: 'bad' },
			{ key: 'count', label: 'count', error: 'bad' },
		])
		expect(validate).toHaveBeenCalledTimes(2)
		expect(validate).toHaveBeenCalledWith('abc', {})
	})

	it('is empty when every value validates', () => {
		expect(propertyErrors({ a: {} }, { valueOf: () => 'ok', validate: () => null })).toEqual([])
	})
})

describe('offersPublishAction', () => {
	const now = new Date('2026-06-01T00:00:00Z')

	it('offers nothing without an object', () => {
		expect(offersPublishAction(null, now)).toBe(false)
	})

	it('offers publishing for a never published or a scheduled object', () => {
		expect(offersPublishAction({}, now)).toBe(true)
		expect(offersPublishAction({ publicationDate: '2026-07-01T00:00:00Z' }, now)).toBe(true)
	})

	it('offers nothing for a live publication', () => {
		expect(offersPublishAction({ publicationDate: '2026-05-01T00:00:00Z' }, now)).toBe(false)
	})

	it('offers publishing again once depublished, not before the depublication date', () => {
		expect(offersPublishAction({ publicationDate: '2026-01-01T00:00:00Z', depublicationDate: '2026-05-01T00:00:00Z' }, now)).toBe(true)
		expect(offersPublishAction({ publicationDate: '2026-01-01T00:00:00Z', depublicationDate: '2026-07-01T00:00:00Z' }, now)).toBe(false)
	})
})
