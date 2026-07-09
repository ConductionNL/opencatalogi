module.exports = {
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest',
		'^.+\\.js$': 'babel-jest',
		'^.+\\.ts$': 'ts-jest',
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub',
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	testEnvironment: 'jest-environment-jsdom',
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
		// Mock the @conduction/nextcloud-vue barrel in unit tests so we
		// don't pull the full Vue component bundle (CSS + ESM dist) into
		// the store-only spec. The store wrapper still uses the barrel at
		// runtime — webpack resolves it correctly in production.
		'^@conduction/nextcloud-vue$': '<rootDir>/tests/mocks/conduction-nextcloud-vue.js',
		// Same rationale as above: stub the handful of NC components used by
		// widget specs so Jest never has to parse @nextcloud/vue's CSS-bearing
		// dist bundle.
		'^@nextcloud/vue$': '<rootDir>/tests/mocks/nextcloud-vue.js',
		// Untranspiled .vue SFCs inside node_modules — see the mock's docblock.
		'^vue-material-design-icons/(.*)\\.vue$': '<rootDir>/tests/mocks/vue-material-design-icon.js',
	},
	coveragePathIgnorePatterns: [
		'index.js',
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
