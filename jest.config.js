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
	},
	coveragePathIgnorePatterns: [
		'index.js',
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
