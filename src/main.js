// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// OpenCatalogi bootstrap — manifest-driven app shell.
//
// Builds the vue-router config from `src/manifest.json`, mounts every
// route with `CnPageRenderer`, passes shallow-cloned `defaultPageTypes`
// + `customComponents` to `CnAppRoot`. Mount survives a missing
// translation file (Apache rewrite under the standard NC dev container
// 404s `/custom_apps/<app>/l10n/<locale>.json`).
//
// @spec openspec/changes/opencatalogi-manifest-v1/spec/REQ-OCMV1-5
// @spec openspec/changes/opencatalogi-manifest-v1/spec/REQ-OCMV1-9

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import {
	translate as t,
	translatePlural as n,
	getLanguage,
	loadTranslations,
	unregister,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import customComponents from './customComponents.js'
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

VueMarkdownEditor.lang.use('en-US', enUS)
Vue.use(VueMarkdownEditor)

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)
Vue.directive('tooltip', Tooltip)

// Register library-side translations once at bootstrap.
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[opencatalogi] registerTranslations failed; falling back to English', e)
}

// Nextcloud falls back to the server's default language (Dutch) when the
// user's language has no l10n file. Unregister those translations so t()
// returns the English key instead.
const _lang = (getLanguage() || 'en').split(/[-_]/)[0]
if (_lang !== 'nl') {
	unregister('opencatalogi')
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
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
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest
 * contract). Routes whose path declares a `:` parameter receive
 * `props: true` so the renderer can read them.
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
	routes: routesFromManifest(bundledManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to App / CnAppRoot. The lib
// exports `defaultPageTypes` (and consumers' `customComponents`) as
// frozen module objects in some bundle shapes — Vue 2's `Vue.extend()`
// mutates component definitions to attach an internal `_Ctor` cache,
// which throws "Cannot add property _Ctor, object is not extensible"
// against a frozen source map. Cloning here yields extensible objects
// without changing the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }

new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest: bundledManifest,
			customComponents: customComponentsProp,
			pageTypes: pageTypesProp,
		},
	}),
}).$mount('#content')
