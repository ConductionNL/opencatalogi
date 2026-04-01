const path = require('path')
const fs = require('fs')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

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
}

webpackConfig.devtool = 'inline-source-map'

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
]

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

// Ensure '@' alias resolves to the project's 'src' directory for cleaner imports like '@/...'
webpackConfig.resolve = webpackConfig.resolve || {}
// When using local nextcloud-vue source, resolve its deps from this app's node_modules
webpackConfig.resolve.modules = [
	path.resolve(__dirname, 'node_modules'),
	'node_modules',
]
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// Deduplicate shared packages so the aliased library source uses
	// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
	'vue$': path.resolve(__dirname, 'node_modules/vue'),
	'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
	// Resolve apexcharts from this app's node_modules (used by CnChartWidget)
	'vue-apexcharts': path.resolve(__dirname, 'node_modules/vue-apexcharts'),
	'apexcharts': path.resolve(__dirname, 'node_modules/apexcharts'),
}

module.exports = webpackConfig
