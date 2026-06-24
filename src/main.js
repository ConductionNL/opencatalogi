// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import customComponents from './registry.js'

import VueMarkdownEditor from '@kangc/v-md-editor'
import '@kangc/v-md-editor/lib/style/base-editor.css'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import '@kangc/v-md-editor/lib/theme/style/github.css'
import hljs from 'highlight.js'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'

// Font Awesome setup
import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { fas } from '@fortawesome/free-solid-svg-icons'
import { fab } from '@fortawesome/free-brands-svg-icons'
import { far } from '@fortawesome/free-regular-svg-icons'
library.add(fas, fab, far)
Vue.component('FontAwesomeIcon', FontAwesomeIcon)

VueMarkdownEditor.use(githubTheme, { Hljs: hljs })
VueMarkdownEditor.lang.use('en-US', enUS)
Vue.prototype.$vMdEditorLang = 'en-US'
Vue.prototype.$vMdEditorLangConfig = { 'en-US': enUS }
Vue.mixin({
	beforeCreate() {
		if (!this.$vMdEditorLang || !this.$vMdEditorLangConfig) {
			Vue.prototype.$vMdEditorLang = 'en-US'
			Vue.prototype.$vMdEditorLangConfig = { 'en-US': enUS }
		}
	},
})
Vue.use(VueMarkdownEditor)

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[opencatalogi] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow
// the JS/CSS allowlist through Apache and rewrite everything else to
// index.php — there's no route for /custom_apps/<app>/l10n/<locale>.json
// so the request 404s. Boot MUST not depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('opencatalogi', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * New ids are appended; existing ids are merged in place: the first
 * definition of `label` / `icon` / `route` / `order` wins (the base manifest
 * loads first, so its canonical group definitions take precedence), and
 * `children` are unioned recursively by the same rule. Fragments may
 * therefore extend an existing group by re-declaring only its `id` plus
 * their own `children`.
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	incoming.forEach((item) => {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({ ...item, children: Array.isArray(item.children) ? [...item.children] : item.children })
			return
		}
		for (const key of ['label', 'icon', 'route', 'order', 'section', 'featureFlag', 'permission', 'visibleIf', 'href', 'action']) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	})
}

/**
 * Merge fragment pages onto the accumulated page list by `id` — a later
 * declaration REPLACES an earlier one wholesale (overlay semantic per ADR-037).
 *
 * @param {Array<object>} target Accumulated pages (mutated in place).
 * @param {Array<object>} incoming Pages from a fragment.
 * @return {void}
 */
function mergePages(target, incoming) {
	incoming.forEach((page) => {
		const idx = target.findIndex((p) => p.id === page.id)
		if (idx === -1) {
			target.push(page)
		} else {
			target[idx] = page
		}
	})
}

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` are merged by `id` (later replaces earlier); `menu`
 * items are merged by `id` (top-level and children) so fragments that re-declare
 * an existing group extend it instead of duplicating it in the navigation.
 * After merging, src/menu-layout.json relocations/sections/removals/settingsSection
 * are applied to consolidate entries into their canonical navigation clusters.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu merged in.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [] }
	mergeMenuItems(merged.menu, base.menu || [])
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a placeholder). It is a no-op when the directory holds no fragments.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			mergePages(merged.pages, frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			mergeMenuItems(merged.menu, frag.menu)
		}
	})
	merged.menu = applyMenuRelocations(merged.menu, menuLayout.relocations)
	merged.menu = applyMenuSections(merged.menu, menuLayout.sections)
	merged.menu = applyMenuRemovals(merged.menu, menuLayout.removals)
	merged.menu = applySettingsSection(merged.menu, menuLayout.settingsSection)
	return merged
}

/**
 * Assign top-level menu leaves to a navigation section declared by
 * `src/menu-layout.json#sections` (`{ menuEntryId: sectionName }`). Section
 * `settings` makes the entry render inside the NC gear-icon settings foldout
 * (CnAppNav) instead of as a top-level item. Only top-level entries are
 * sectioned; unknown ids are inert.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} sections Menu-entry id → section name.
 * @return {Array<object>} The menu with sections applied.
 */
function applyMenuSections(menu, sections) {
	if (!sections || typeof sections !== 'object') return menu
	menu.forEach((node) => {
		const section = sections[node.id]
		if (section) node.section = section
	})
	return menu
}

/**
 * Re-home merged menu entries onto the canonical navigation layout declared
 * by `src/menu-layout.json#relocations` (`{ sourceId: targetGroupId }`).
 *
 * Fragments stay the canonical source of WHAT exists in the menu (per
 * ADR-037 they drop entries wherever their change authored them); this map
 * is the single place that decides WHERE entries live, so the navigation
 * can be consolidated without rewriting dozens of fragments:
 *
 *  - A relocated GROUP dissolves: its children merge (by id) into the
 *    target group and the now-empty shell is dropped.
 *  - A relocated LEAF (top-level or child of any group) moves under the
 *    target group.
 *  - A child group relocated onto its own parent flattens into it.
 *  - Unknown source ids are inert; a missing target group keeps the entry
 *    at the top level so nothing silently disappears.
 *
 * Runs in passes until stable (children freed by a dissolved group can
 * themselves be relocated on the next pass).
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children)) continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	return menu.filter((m) => m.type === 'caption' || m.route || m.href || m.action
		|| (Array.isArray(m.children) && m.children.length > 0))
}

/**
 * Remove individual menu entries by id after relocation — used to retire
 * duplicate navigation entries whose PAGE must stay routable (deep links
 * and e2e specs hit the route directly). Declared in
 * `src/menu-layout.json#removals`. Only leaf entries are removed; group ids
 * are ignored so a removal can never silently hide a whole cluster.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const isLeaf = (n) => !Array.isArray(n.children) || n.children.length === 0
	menu.forEach((node) => {
		if (Array.isArray(node.children)) {
			node.children = node.children.filter((c) => !(drop.has(c.id) && isLeaf(c)))
		}
	})
	return menu.filter((node) => !(drop.has(node.id) && isLeaf(node)))
}

/**
 * Promote the menu entries listed in `src/menu-layout.json#settingsSection`
 * into Nextcloud's settings foldout — the NcAppNavigationSettings gear at the
 * bottom-left of the navigation, OUTSIDE the scrollable list. CnAppNav renders
 * every TOP-LEVEL item carrying `section: "settings"` as a flat entry inside
 * that foldout (with an auto-prepended "Personal settings"). This lifts each
 * listed id out of wherever it currently sits, tags it `section: "settings"`,
 * flattens it (the foldout has no nested groups), and appends it to the top
 * level. Empty non-clickable groups left behind are dropped; a clickable group
 * (one with route/href/action) is kept.
 *
 * @param {Array<object>} menu        The merged + relocated + pruned menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const want = new Set(settingsIds)
	const isClickable = (n) => n.route !== undefined || n.href !== undefined || n.action !== undefined
	const lifted = []
	const strip = (nodes) => nodes.reduce((acc, n) => {
		if (want.has(n.id)) {
			const { children, ...leaf } = n
			lifted.push({ ...leaf, section: 'settings' })
			return acc
		}
		if (Array.isArray(n.children)) {
			const children = strip(n.children)
			if (children.length === 0 && n.children.length > 0 && !isClickable(n)) return acc
			acc.push({ ...n, children })
			return acc
		}
		acc.push(n)
		return acc
	}, [])
	const remaining = strip(menu)
	return [...remaining, ...lifted]
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
 */
function resolveManifestSentinelsSync(manifest) {
	const SENTINEL = /^@resolve:([a-z][a-z0-9_-]*)$/
	const cache = new Map()
	const lookup = (key) => {
		if (cache.has(key)) {
			return cache.get(key)
		}
		let value = null
		try {
			const v = loadState('opencatalogi', key, null)
			if (v !== undefined && v !== null && v !== '') {
				value = v
			}
		} catch (e) {
			value = null
		}
		cache.set(key, value)
		return value
	}
	const substitute = (node) => {
		if (Array.isArray(node)) {
			return node.map(substitute)
		}
		if (node !== null && typeof node === 'object') {
			const out = {}
			for (const [k, v] of Object.entries(node)) {
				out[k] = substitute(v)
			}
			return out
		}
		if (typeof node === 'string') {
			const m = node.match(SENTINEL)
			if (m) {
				const resolved = lookup(m[1])
				return resolved !== null ? resolved : node
			}
		}
		return node
	}
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	return {
		...manifest,
		pages: pages.map((page) => (
			page && typeof page === 'object' && page.config && typeof page.config === 'object'
				? { ...page, config: substitute(page.config) }
				: page
		)),
	}
}

const resolvedManifest = resolveManifestSentinelsSync(mergedManifest)

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * underlying custom component receives the route param.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'hash',
	base: generateUrl('/apps/opencatalogi'),
	routes: routesFromManifest(resolvedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen module
// objects in some bundle shapes — Vue 2's `Vue.extend()` mutates component
// definitions to attach an internal `_Ctor` cache, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source map. Cloning here yields extensible objects without changing
// the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }

new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest: resolvedManifest,
			customComponents: customComponentsProp,
			pageTypes: pageTypesProp,
		},
	}),
}).$mount('#content')
