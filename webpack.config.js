const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')
const TerserPlugin = require('terser-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// No production source maps: they inflate output size and heap usage dramatically.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

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
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.ts$/,
			loader: 'ts-loader',
			exclude: /node_modules/,
			options: { appendTsSuffixTo: [/\.vue$/] },
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	// TODO: Remove NodePolyfillPlugin when upgrading to Vue 3. This is a temporary hack required
	// because we are using an outdated version of @nextcloud/vue which still targets Vue 2.
	new NodePolyfillPlugin({
		additionalAliases: ['process'],
	}),
	new webpack.DefinePlugin({ appName: JSON.stringify(process.env.npm_package_name) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	vue$: path.resolve(__dirname, 'node_modules/vue'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	'@nextcloud/dialogs$': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
}

if (isDev === false) {
	webpackConfig.optimization = webpackConfig.optimization || {}

	// Minify with esbuild instead of Terser: far lower peak RAM and much faster.
	// esbuild parallelises internally (Go), so disable webpack-worker parallelism
	// to avoid spawning redundant Node processes.
	webpackConfig.optimization.minimizer = [
		new TerserPlugin({
			minify: TerserPlugin.esbuildMinify,
			parallel: false,
			terserOptions: {
				legalComments: 'eof',
			},
		}),
	]

	// The in-memory build cache is unused in a single-shot production build and
	// only adds heap pressure.
	webpackConfig.cache = false

	// De-duplicate node_modules across the five entrypoints into a single shared
	// vendor chunk instead of bundling vue/@nextcloud/vue/pinia/etc. once per entry.
	// This is the main output-size and RAM win. The emitted vendor chunk must be
	// loaded before each entry script (handled PHP-side by ScriptManifestLoader).
	webpackConfig.optimization.splitChunks = {
		chunks: 'all',
		cacheGroups: {
			vendor: {
				test: /[\\/]node_modules[\\/]/,
				name: 'vendor',
				priority: 10,
			},
		},
	}
}

// Emit a manifest mapping each entrypoint to its ordered list of initial JS chunks
// (runtime, shared vendor, entry). ScriptManifestLoader.php reads this so every entry
// loads its split chunks in the correct order, with a fallback to the single-script
// name when the manifest is absent.
webpackConfig.plugins.push({
	apply(compiler) {
		compiler.hooks.afterEmit.tap('OpenCatalogiEntrypointsManifest', (compilation) => {
			const manifest = {}
			for (const [name, entrypoint] of compilation.entrypoints) {
				manifest[name] = entrypoint
					.getFiles()
					.map((file) => file.split('?')[0])
					.filter((file) => file.endsWith('.js'))
			}
			fs.writeFileSync(
				path.join(compiler.options.output.path, 'opencatalogi-entrypoints.json'),
				JSON.stringify(manifest, null, '\t') + '\n',
			)
		})
	},
})

module.exports = webpackConfig
