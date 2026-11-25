/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Jest stand-in for `@nextcloud/browser-storage`, which ships ESM only.
 *
 * `@nextcloud/auth`'s CommonJS build requires it to keep the guest name in a
 * scoped storage; jest cannot load the ESM build, so every spec importing a
 * component that imports `@nextcloud/auth` failed to run. This keeps the same
 * builder chain over an in-memory store.
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => {
			items.set(key, String(value))
		},
		removeItem: (key) => {
			items.delete(key)
		},
		clear: () => {
			items.clear()
		},
	}
}

function getBuilder() {
	const builder = {
		persist: () => builder,
		clearOnLogout: () => builder,
		build: () => memoryStorage(),
	}
	return builder
}

module.exports = {
	getBuilder,
	clearAll: () => {},
	clearNonPersistent: () => {},
}
