/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Cross-component event bus.
 *
 * Vue 2 shipped `$on` / `$off` / `$emit` on every instance, so the idiomatic
 * bus was a throwaway `new Vue()`. Vue 3 REMOVED those three methods from the
 * instance API entirely, and `createApp()` returns an app handle that never had
 * them — so the old spelling does not merely warn, it throws
 * `EventBus.$on is not a function` the first time a listener registers.
 *
 * This is a small emitter exposing the SAME three method names, so the 23 call
 * sites across the app need no edit and no behavioural review. It is not a Vue
 * component and holds no reactivity: the bus never needed either.
 *
 * `$off` with a handler removes exactly that handler (matching Vue 2); `$off`
 * with only an event name clears every listener for it.
 */

/** @type {Map<string, Set<Function>>} */
const listeners = new Map()

export const EventBus = {
	/**
	 * Subscribe to an event.
	 *
	 * @param {string} event Event name.
	 * @param {Function} handler Callback invoked with the emitted payload.
	 * @return {void}
	 */
	$on(event, handler) {
		if (typeof handler !== 'function') {
			return
		}
		if (!listeners.has(event)) {
			listeners.set(event, new Set())
		}
		listeners.get(event).add(handler)
	},

	/**
	 * Unsubscribe. Omit `handler` to drop every listener for the event.
	 *
	 * @param {string} event Event name.
	 * @param {Function} [handler] The exact handler passed to `$on`.
	 * @return {void}
	 */
	$off(event, handler) {
		const set = listeners.get(event)
		if (!set) {
			return
		}
		if (handler === undefined) {
			listeners.delete(event)
			return
		}
		set.delete(handler)
		if (set.size === 0) {
			listeners.delete(event)
		}
	},

	/**
	 * Emit an event to every current subscriber.
	 *
	 * Iterates a COPY of the set so a handler that unsubscribes itself (or
	 * another handler) mid-dispatch cannot corrupt the iteration.
	 *
	 * @param {string} event Event name.
	 * @param {*} [payload] Value passed to each handler.
	 * @return {void}
	 */
	$emit(event, payload) {
		const set = listeners.get(event)
		if (!set) {
			return
		}
		for (const handler of [...set]) {
			handler(payload)
		}
	},
}
