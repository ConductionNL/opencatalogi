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
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` and `menu` arrays are concatenated.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu appended.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [...(base.menu || [])] }
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a placeholder). It is a no-op when the directory holds no fragments.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			merged.pages.push(...frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			merged.menu.push(...frag.menu)
		}
	})
	return merged
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
	mode: 'history',
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
