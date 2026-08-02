const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

// `@nextcloud/webpack-vue-config` hardcodes `publicPath` to `/apps/<appId>/js/`,
// but an app installed under `custom_apps/` is served from
// `/custom_apps/<appId>/js/`. The wrong path does NOT 404: Nextcloud answers
// 200 with `text/html`, so the browser reports a MIME refusal and a
// `ChunkLoadError` instead of a missing file.
//
// Vue 2 never surfaced this here because the old dependency set emitted no
// async chunks. The Vue 3 set (@nextcloud/dialogs@7, @nextcloud/files,
// @nextcloud/paths, @mdi/js) splits into dozens, and the ENTRY bundle is
// unaffected — so the build looks clean and the app loads; only lazily
// visited routes break.
//
// `publicPath: 'auto'` lets webpack derive the path from the script's own URL
// at runtime, which is correct under BOTH /apps and /custom_apps. It also
// covers all SEVEN entry points; the previous `__webpack_public_path__`
// assignment lived in `src/main.js` only, so `settings.js` and the five
// dashboard-widget entries were never covered.
webpackConfig.output = { ...webpackConfig.output, publicPath: 'auto' }

const appId = 'opencatalogi'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	catalogiWidget: {
		import: path.join(__dirname, 'src', 'catalogiWidget.js'),
		filename: appId + '-catalogiWidget.js',
	},
	unpublishedPublicationsWidget: {
		import: path.join(__dirname, 'src', 'unpublishedPublicationsWidget.js'),
		filename: appId + '-unpublishedPublicationsWidget.js',
	},
	unpublishedAttachmentsWidget: {
		import: path.join(__dirname, 'src', 'unpublishedAttachmentsWidget.js'),
		filename: appId + '-unpublishedAttachmentsWidget.js',
	},
	mostViewedPublicationsWidget: {
		import: path.join(__dirname, 'src', 'mostViewedPublicationsWidget.js'),
		filename: appId + '-mostViewedPublicationsWidget.js',
	},
	retentionWidget: {
		import: path.join(__dirname, 'src', 'retentionWidget.js'),
		filename: appId + '-retentionWidget.js',
	},
}

// Drop the base config's ts-loader rule (it type-checks the entire project
// against `tsconfig.json`'s strict mode, surfacing 351 pre-existing TS
// errors that pre-date this change and gate the build for unrelated reasons)
// AND breaks webpack's module-id stability across split chunks (ADR-004 →
// "Build / bundling — known limitation"). Replace with a babel-loader rule
// that uses @babel/preset-typescript to strip types only — same toolchain
// as the .js files. Type-checking moves to `npx tsc --noEmit` (run separately
// or in CI), where it can fail loud without blocking the bundle.
webpackConfig.module.rules = webpackConfig.module.rules.filter(rule =>
	!(rule && rule.use && (
		(typeof rule.use === 'string' && rule.use === 'ts-loader')
		|| (Array.isArray(rule.use) && rule.use.some(u => (u?.loader || u) === 'ts-loader'))
		|| (typeof rule.use === 'object' && (rule.use.loader === 'ts-loader'))
	))
	&& !(rule && rule.loader === 'ts-loader')
)
webpackConfig.module.rules.push({
	test: /\.ts$/,
	exclude: /node_modules/,
	use: { loader: 'babel-loader' },
})
webpackConfig.module.rules.push({
	test: /\.scss$/,
	use: ['style-loader', 'css-loader', 'sass-loader'],
})

// `@nextcloud/vue` reads the build-time `appName` / `appVersion` constants
// to identify the host app in console messages and telemetry. The base config
// sets these defines but our `webpackConfig.plugins` replacement below drops
// them, so we re-add explicitly.
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	// TODO: Remove NodePolyfillPlugin when upgrading to Vue 3.
	new NodePolyfillPlugin({ additionalAliases: ['process'] }),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Use local source when available (monorepo dev), otherwise fall back to the
// npm package.
//
// ⚠️ `USE_LOCAL_LIB` is opt-OUT across the fleet, and the shared
// `apps-extra/nextcloud-vue` checkout sits on the Vue 2 (`beta.*`) line — so a
// default-on local-lib build silently compiles Vue 2 SOURCES into this Vue 3
// app. The guard below refuses that instead of producing a broken bundle.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib && fs.existsSync(localLibPkg)) {
	const localVersion = String(JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '')
	const localMajor = parseInt(localVersion, 10)
	if (!Number.isInteger(localMajor) || localMajor < 2) {
		// eslint-disable-next-line no-console
		console.warn(
			`[opencatalogi] IGNORING USE_LOCAL_LIB: ../nextcloud-vue is ${localVersion || 'unversioned'}, `
			+ 'which is the Vue 2 line. Building against node_modules instead.',
		)
		useLocalLib = false
	}
}

webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.extensions = ['.ts', '.js', '.vue', '.json']
// `@conduction/nextcloud-vue` bundles a FilePicker chunk that imports node's
// `path`; webpack 5 no longer polyfills node builtins automatically. It must be
// a REAL polyfill, not `false` — an empty stub makes `path.join` undefined and
// the picker throws at runtime.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
}
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// Deduplicate the packages that MUST be single instances. A second copy of
	// vue / pinia / vue-router means a second set of module-local injection
	// symbols, so `inject()` / `useRouter()` silently return the fallback
	// instead of the real thing — no error, just a dead feature.
	vue$: path.resolve(__dirname, 'node_modules/vue'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
}

// The Vue 3 lines of `@nextcloud/vue` (v9) and `@nextcloud/dialogs` (v7) are
// ESM-only: their package.json has NO `main` and NO `module`, only an `exports`
// map. A Vue-2-era DIRECTORY alias —
//     '@nextcloud/vue$': path.resolve('node_modules/@nextcloud/vue')
// — therefore resolves to nothing, because webpack applies an exports map to
// package REQUESTS and never to an already-absolutised path. The previous
// bare-directory `@nextcloud/dialogs` alias had the same defect.
//
// Aliasing to the concrete ESM ENTRY FILE sidesteps exports resolution
// entirely. The exact-match (`$`) form keeps deep imports such as
// `@nextcloud/vue/components/NcButton` going through the exports map.
webpackConfig.resolve.alias['@nextcloud/vue$'] = path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs')
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/index.mjs')
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css')

// `@nextcloud/vue@9` hard-depends on `vue-router@^5.1.0` while this app is on
// v4, so npm nests a SECOND copy under node_modules/@nextcloud/vue. Two copies
// of vue-router means two `routerKey` symbols: any nc-vue component calling
// `useRouter()` gets `undefined` rather than this app's router, with no error.
// Pin every request to the app's single copy.
webpackConfig.resolve.alias['vue-router$'] = path.resolve(__dirname, 'node_modules/vue-router/dist/vue-router.mjs')

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across every entry-point so each widget bundle no longer inlines its own
// ~3 MB framework copy. Stable filenames (no contenthash in the JS name)
// mean each widget's `Util::addScript` PHP call can reference the chunk
// directly without a manifest. The shared chunks load once on the page and
// stay cached across navigations between opencatalogi's own pages.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
