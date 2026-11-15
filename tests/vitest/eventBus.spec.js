/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the cross-component event bus that replaces Vue 2's
 * instance `$on` / `$off` / `$emit`.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { EventBus } from '../../src/eventBus.js'

const EVENTS = ['saved', 'deleted', 'other']

afterEach(() => {
	EVENTS.forEach((event) => EventBus.$off(event))
})

describe('EventBus', () => {
	it('delivers the payload to every subscriber of the event', () => {
		const first = vi.fn()
		const second = vi.fn()
		EventBus.$on('saved', first)
		EventBus.$on('saved', second)

		EventBus.$emit('saved', { id: 3 })

		expect(first).toHaveBeenCalledExactlyOnceWith({ id: 3 })
		expect(second).toHaveBeenCalledExactlyOnceWith({ id: 3 })
	})

	it('does not deliver to subscribers of another event', () => {
		const handler = vi.fn()
		EventBus.$on('deleted', handler)

		EventBus.$emit('saved', 1)

		expect(handler).not.toHaveBeenCalled()
	})

	it('registers the same handler only once per event', () => {
		const handler = vi.fn()
		EventBus.$on('saved', handler)
		EventBus.$on('saved', handler)

		EventBus.$emit('saved')

		expect(handler).toHaveBeenCalledTimes(1)
	})

	it.each([[undefined], [null], ['handler'], [{}]])(
		'ignores a non-function handler %j',
		(handler) => {
			EventBus.$on('saved', handler)
			expect(() => EventBus.$emit('saved', 1)).not.toThrow()
		},
	)

	it('emitting an event without subscribers is a no-op', () => {
		expect(EventBus.$emit('other', 1)).toBeUndefined()
	})

	it('$off with a handler removes only that handler', () => {
		const kept = vi.fn()
		const removed = vi.fn()
		EventBus.$on('saved', kept)
		EventBus.$on('saved', removed)

		EventBus.$off('saved', removed)
		EventBus.$emit('saved', 'x')

		expect(removed).not.toHaveBeenCalled()
		expect(kept).toHaveBeenCalledExactlyOnceWith('x')
	})

	it('$off without a handler clears every listener of the event', () => {
		const first = vi.fn()
		const second = vi.fn()
		const elsewhere = vi.fn()
		EventBus.$on('saved', first)
		EventBus.$on('saved', second)
		EventBus.$on('deleted', elsewhere)

		EventBus.$off('saved')
		EventBus.$emit('saved')
		EventBus.$emit('deleted')

		expect(first).not.toHaveBeenCalled()
		expect(second).not.toHaveBeenCalled()
		expect(elsewhere).toHaveBeenCalledTimes(1)
	})

	it('$off of the last handler lets the event be subscribed afresh', () => {
		const handler = vi.fn()
		EventBus.$on('saved', handler)
		EventBus.$off('saved', handler)
		EventBus.$on('saved', handler)

		EventBus.$emit('saved')

		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('$off of an unknown event or handler does nothing', () => {
		const handler = vi.fn()
		EventBus.$on('saved', handler)

		expect(() => EventBus.$off('other', handler)).not.toThrow()
		EventBus.$off('saved', vi.fn())
		EventBus.$emit('saved')

		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('a handler unsubscribing during dispatch does not skip the others', () => {
		const calls = []
		const second = () => calls.push('second')
		const first = () => {
			calls.push('first')
			EventBus.$off('saved', first)
			EventBus.$off('saved', second)
		}
		EventBus.$on('saved', first)
		EventBus.$on('saved', second)

		EventBus.$emit('saved')
		EventBus.$emit('saved')

		expect(calls).toEqual(['first', 'second'])
	})
})
