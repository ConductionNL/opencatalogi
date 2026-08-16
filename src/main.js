// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnFileManager,
	CnPageRenderer,
	CnRelationshipGraph,
	CnTreeView,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerDashboardWidget,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
// Font Awesome setup
import { library } from '@fortawesome/fontawesome-svg-core'
import { fab } from '@fortawesome/free-brands-svg-icons'
import { far } from '@fortawesome/free-regular-svg-icons'
import { fas } from '@fortawesome/free-solid-svg-icons'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import VueMarkdownEditor from '@kangc/v-md-editor'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import { loadState } from '@nextcloud/initial-state'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import hljs from 'highlight.js'
import { createApp, h } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './App.vue'
import AuditTrailWidget from './components/widgets/AuditTrailWidget.vue'
import ThemePreviewWidget from './components/widgets/ThemePreviewWidget.vue'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import customComponents from './registry.js'

// gridstack v12 sizes dashboard items with `width: var(--gs-column-width)`.
// Without this stylesheet every dashboard item renders 0 px wide, with no
// error and correct heights — the height comes from JS, the width from CSS.
import 'gridstack/dist/gridstack.min.css'
// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
import '@kangc/v-md-editor/lib/style/base-editor.css'
import '@kangc/v-md-editor/lib/theme/style/github.css'
library.add(fas, fab, far)

// The runtime public path is now set by webpack itself (`output.publicPath:
// 'auto'` in webpack.config.js), which derives it from the loading script's own
// URL. That covers ALL SEVEN entry points; the previous
// `__webpack_public_path__ = generateFilePath(...)` assignment lived here only,
// so settings.js and the five dashboard-widget entries were never covered.

// nc-vue declares `sideEffects: ["**/*.css"]`, which lets webpack drop the bare
// side-effect imports that register the built-in `stat` and `object-table`
// dashboard widgets. Without this explicit call they render
// "Widget not available" at runtime with no error — `chart` survives only
// because it is registered inline, and that asymmetry is the tell.
registerBuiltinDashboardWidgets()

// Register detail-page widget keys into the shared dashboard widget catalog
// so CnDetailPage's config-grid body (which resolves `config.widgets[].type`
// via this catalog, not the app's customComponents map) can render them.
// CnWidgetGrid's page-level `widgetKey` path ALSO falls back to this same
// catalog (after cnRegistry/BUILT_IN_WIDGETS), so registering here is the one
// mechanism that works for both render paths. Note: this app never passes a
// `registry` prop to CnAppRoot (only `customComponents`, a different inject),
// so `theme-preview` / `tree-view` / `relationship-graph` were previously
// unresolved on the page-level path too — this registration fixes that as
// a side effect.
registerDashboardWidget('audit-trail', {
	renderer: AuditTrailWidget,
	form: null,
	defaultContent: {},
	displayName: 'Audit trail',
	icon: 'History',
	surfaces: ['detail-page'],
})
// `theme-preview` is registered with the local `ThemePreviewWidget` adapter,
// not the library's `CnThemePreview` directly: CnThemePreview requires a
// non-empty `pickers` prop with no default, and ThemeDetail's manifest entry
// carries no `content.pickers` (the Theme schema has no colour fields to seed
// it from) — mounting the raw component there crashed with
// `TypeError: this.pickers is not iterable`. The adapter guarantees a valid
// pickers/defaults/value shape (falling back to a catalog-brand default set)
// so the widget always renders instead of crashing silently.
registerDashboardWidget('theme-preview', {
	renderer: ThemePreviewWidget,
	form: null,
	defaultContent: {},
	displayName: 'Theme preview',
	icon: 'Palette',
	surfaces: ['detail-page'],
})
registerDashboardWidget('tree-view', {
	renderer: CnTreeView,
	form: null,
	defaultContent: {},
	displayName: 'Tree view',
	icon: 'FileTree',
	surfaces: ['detail-page'],
})
registerDashboardWidget('relationship-graph', {
	renderer: CnRelationshipGraph,
	form: null,
	defaultContent: {},
	displayName: 'Relationship graph',
	icon: 'Graph',
	surfaces: ['detail-page'],
})
// `file-manager` (CnFileManager) is imported for parity with registry.js's
// customComponents map, though no current manifest page references it — the
// `integration` widget type (OpenRegister files leaf) supersedes it. Kept
// registered so any future manifest entry resolves it, without importing
// CnFileManager twice across the two module-scope registries.
registerDashboardWidget('file-manager', {
	renderer: CnFileManager,
	form: null,
	defaultContent: {},
	displayName: 'File manager',
	icon: 'Folder',
	surfaces: ['detail-page'],
})

VueMarkdownEditor.use(githubTheme, { Hljs: hljs })
VueMarkdownEditor.lang.use('en-US', enUS)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn(
		'[opencatalogi] registerTranslations failed; falling back to English',
		e,
	)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow
// the JS/CSS allowlist through Apache and rewrite everything else to
// index.php — there's no route for /custom_apps/<app>/l10n/<locale>.json
// so the request 404s. Boot MUST not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('opencatalogi', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
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

// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

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
		pages: pages.map((page) =>
			page
			&& typeof page === 'object'
			&& page.config
			&& typeof page.config === 'object'
				? { ...page, config: substitute(page.config) }
				: page,
		),
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
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	//
	// ⚠️ vue-router 4 REMOVED the bare `path: '*'` wildcard. It does not throw
	// and it does not warn in a production build — the route simply never
	// matches, so an unknown hash renders the app shell with an empty <main>.
	// The v4 spelling is a named catch-all param.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/opencatalogi')),
	routes: routesFromManifest(resolvedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) FROZEN in some bundle
// shapes, so any consumer that mutates them throws. Cloning here yields
// extensible objects without changing the values the lib resolves at render
// time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }

const app = createApp({
	render: () =>
		h(App, {
			manifest: resolvedManifest,
			customComponents: customComponentsProp,
			pageTypes: pageTypesProp,
		}),
})

// Vue 3 has no `Vue.prototype`; per-app globals live on
// `app.config.globalProperties`.
app.config.globalProperties.$vMdEditorLang = 'en-US'
app.config.globalProperties.$vMdEditorLangConfig = { 'en-US': enUS }

app.mixin({ methods: { t, n } })
app.use(VueMarkdownEditor)
app.use(pinia)
app.use(router)
app.component('FontAwesomeIcon', FontAwesomeIcon)

// ⚠️ Vue 2's `$mount('#content')` REPLACED the matched element; Vue 3's
// `mount()` renders INSIDE it. `templates/index.php` emits
// `<div id="opencatalogi">`, but this bootstrap mounted on `#content` — which
// is Nextcloud core's OWN wrapper from `layout.user.php`. Under Vue 2 that
// replaced core's wrapper and the app's own div was simply never used; under
// Vue 3 the same selector would nest the whole app inside core's chrome and
// leave `#opencatalogi` empty. Mount on the app's own, uniquely named host.
app.mount('#opencatalogi')
