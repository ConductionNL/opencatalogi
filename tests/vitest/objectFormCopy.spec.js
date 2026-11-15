/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for objectFormCopy: the editable copy of an object a modal
 * starts from, and which catalog a new publication starts in (Vitest
 * offline suite).
 */
import { describe, expect, it } from 'vitest'
import { editableFormData, initialCatalog } from '../../src/modals/object/objectFormCopy.js'

describe('editableFormData', () => {
	it('drops @self and id, keeps the rest as a deep copy', () => {
		const initial = { '@self': { id: 'x' }, id: 'x', title: 'T', nested: { a: [1, 2] } }
		const form = editableFormData(initial)

		expect(form).toEqual({ title: 'T', nested: { a: [1, 2] } })
		form.nested.a.push(3)
		expect(initial.nested.a).toEqual([1, 2])
	})

	it('turns theme objects into their ids and keeps bare ids', () => {
		expect(editableFormData({ themes: [{ id: 't1', title: 'One' }, 't2'] }).themes).toEqual(['t1', 't2'])
	})

	it('leaves a themes value that is not a list alone', () => {
		expect(editableFormData({ themes: 'none' }).themes).toBe('none')
	})

	it('keeps a value that cannot be serialised as it is', () => {
		const cyclic = { name: 'loop' }
		cyclic.self = cyclic

		const form = editableFormData({ title: 'T', cyclic })

		expect(form.title).toBe('T')
		expect(form.cyclic).toBe(cyclic)
	})

	it('copies an empty object to an empty form', () => {
		expect(editableFormData({})).toEqual({})
	})
})

describe('initialCatalog', () => {
	const catalogs = [{ slug: 'a' }, { slug: 'b' }]

	it('takes the catalog named by the slug', () => {
		expect(initialCatalog(catalogs, 'b')).toBe(catalogs[1])
	})

	it('is undefined for a slug no catalog has', () => {
		expect(initialCatalog(catalogs, 'z')).toBeUndefined()
	})

	it('takes the only catalog without a slug, and none of several', () => {
		expect(initialCatalog([catalogs[0]], '')).toBe(catalogs[0])
		expect(initialCatalog(catalogs, null)).toBeUndefined()
	})
})
