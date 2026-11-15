/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Substitutes the `@resolve:<key>` sentinels in a manifest's page configs
 * with the app-config values UiController provides as initial state.
 */

import { loadState } from '@nextcloud/initial-state'

/** The `@resolve:<key>` sentinel; the key is lower case. */
const SENTINEL = /^@resolve:([a-z][a-z0-9_-]*)$/

/**
 * Synchronously substitute every `@resolve:<key>` sentinel under
 * `pages[].config` with the matching IAppConfig value surfaced as
 * initial-state by UiController. This MUST run before the router and the
 * CnAppRoot `manifest` prop are built: the library's async `useAppManifest`
 * resolver only updates the manifest AFTER first paint, by which time
 * CnIndexPage has already registered its object-type from the (then still
 * unresolved) config and self-fetched the 404 `@resolve:...` URL. Resolving
 * up-front guarantees every page renders with real register/schema ids from
 * the very first mount. Unknown / unset keys are left untouched.
 *
 * @param {object} manifest The merged manifest.
 * @return {object} A new manifest with sentinels substituted in pages[].config.
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
export function resolveManifestSentinelsSync(manifest) {
	const lookup = cachedSettingLookup()
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	return {
		...manifest,
		pages: pages.map((page) =>
			hasObjectConfig(page)
				? { ...page, config: substitute(page.config, lookup) }
				: page,
		),
	}
}

/**
 * Whether an initial-state value counts as set.
 *
 * @param {unknown} value The value
 * @return {boolean} False for undefined, null and the empty string
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function isSet(value) {
	return value !== undefined && value !== null && value !== ''
}

/**
 * The opencatalogi initial-state value for a key, or null when it is unset or unreadable.
 *
 * @param {string} key The initial-state key
 * @return {unknown} The value, or null
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function readSetting(key) {
	try {
		const value = loadState('opencatalogi', key, null)
		return isSet(value) ? value : null
	} catch {
		return null
	}
}

/**
 * A lookup that reads each initial-state key once.
 *
 * @return {(key: string) => unknown} The lookup
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function cachedSettingLookup() {
	const cache = new Map()
	return (key) => {
		if (!cache.has(key)) {
			cache.set(key, readSetting(key))
		}
		return cache.get(key)
	}
}

/**
 * Whether a page carries an object config to substitute in.
 *
 * @param {unknown} page The page
 * @return {boolean} True when page.config is an object
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function hasObjectConfig(page) {
	return Boolean(
		page
		&& typeof page === 'object'
		&& page.config
		&& typeof page.config === 'object',
	)
}

/**
 * Substitute the sentinels in a config value, recursing into lists and objects.
 *
 * @param {unknown} node The value
 * @param {(key: string) => unknown} lookup The setting lookup
 * @return {unknown} A copy with the set sentinels substituted
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function substitute(node, lookup) {
	if (Array.isArray(node)) {
		return node.map((item) => substitute(item, lookup))
	}
	if (node !== null && typeof node === 'object') {
		return substituteEntries(node, lookup)
	}
	if (typeof node === 'string') {
		return substituteSentinel(node, lookup)
	}
	return node
}

/**
 * Substitute the sentinels in every property of an object.
 *
 * @param {object} node The object
 * @param {(key: string) => unknown} lookup The setting lookup
 * @return {object} A copy with the set sentinels substituted
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function substituteEntries(node, lookup) {
	const out = {}
	for (const [key, value] of Object.entries(node)) {
		out[key] = substitute(value, lookup)
	}
	return out
}

/**
 * The value a sentinel string resolves to, or the string itself.
 *
 * @param {string} node The string
 * @param {(key: string) => unknown} lookup The setting lookup
 * @return {unknown} The set value, or the string unchanged
 * @spec exclude pure framework plumbing: resolves manifest config sentinels from initial state.
 */
function substituteSentinel(node, lookup) {
	const match = node.match(SENTINEL)
	if (!match) {
		return node
	}
	const resolved = lookup(match[1])
	return resolved !== null ? resolved : node
}
