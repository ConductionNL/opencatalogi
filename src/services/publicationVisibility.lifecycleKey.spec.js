/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The status card reads its state again when the record's lifecycle state or
 * dates change, so a move from the page's Actions menu is reflected in the
 * moves it offers (officer-flow-accessibility, REQ-OFA-002).
 *
 * @spec openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-the-officer-flow-works-from-the-keyboard-alone-req-ofa-002
 */
import { lifecycleKeyOf } from './publicationVisibility.js'

describe('lifecycleKeyOf', () => {
	it('keys a record on its id, state and dates', () => {
		expect(lifecycleKeyOf({ id: 'a', status: 'approved', publicationDate: null })).toBe('a|approved||')
	})

	it('changes when the record is published', () => {
		const before = lifecycleKeyOf({ id: 'a', status: 'approved' })
		const after = lifecycleKeyOf({ id: 'a', status: 'published', publicationDate: '2026-11-25T09:00:00Z' })
		expect(after).not.toBe(before)
	})

	it('reads the state from @self when the record carries no status', () => {
		expect(lifecycleKeyOf({ '@self': { id: 'b', state: 'published' } })).toBe('b|published||')
	})

	it('stays the same while nothing that matters changes', () => {
		expect(lifecycleKeyOf({ id: 'a', status: 'draft', title: 'One' })).toBe(lifecycleKeyOf({ id: 'a', status: 'draft', title: 'Two' }))
	})

	it('answers null without a record or an id', () => {
		expect(lifecycleKeyOf(null)).toBeNull()
		expect(lifecycleKeyOf({ status: 'draft' })).toBeNull()
	})
})
