const path = require('path')
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
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
]

// Ensure '@' alias resolves to the project's 'src' directory for cleaner imports like '@/...'
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
}

module.exports = webpackConfig
