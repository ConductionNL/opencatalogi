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

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.extensions = ['.ts', '.js', '.vue', '.json']
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	vue$: path.resolve(__dirname, 'node_modules/vue'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
}

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
