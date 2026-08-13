const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

// The shared Vue 3 rule set ships INSIDE @conduction/nextcloud-vue, so it can
// only be enabled after the dependency bump — not before.
//
// It is an ARRAY OF THREE configs, not one object, and it registers no plugins,
// which is why it layers cleanly on top of the `@nextcloud` v8 base. It must be
// spread LAST so its severities win.
//
// Do NOT reach for `@nextcloud/eslint-config/vue3` instead: that preset sets
// `parserOptions.parser` to a bare string, which routes template expressions
// through @typescript-eslint/parser, drops `v-for` scope, and manufactures
// hundreds of bogus `vue/valid-v-for` errors.
//
// From CJS the extensionless subpath works (the package ships no `exports`
// map); from ESM it would need `@conduction/nextcloud-vue/eslint/index.js`.
const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

module.exports = defineConfig([
	{ ignores: ['l10n/**'] },

	...compat.extends('@nextcloud'),

	{
		languageOptions: {
			// set latest version of ECMAScript
			// default (non explicitly set) causes errors when importing
			ecmaVersion: 'latest',
			sourceType: 'module',

			// also pass through to parsers that still read parserOptions
			parserOptions: {
				ecmaVersion: 'latest',
				sourceType: 'module',
			},
		},

		settings: {
			'import/resolver': {
				alias: {
					map: [['@', './src']],
					extensions: ['.js', '.ts', '.vue', '.json'],
				},
			},

			// import/parsers is used to parse the files
			// espree is used to parse the JavaScript files
			// @typescript-eslint/parser is used to parse the TypeScript files
			// vue-eslint-parser is used to parse the Vue files
			'import/parsers': {
				espree: ['.js', '.mjs', '.cjs', '.jsx'],
				'@typescript-eslint/parser': ['.ts', '.tsx', '.mts', '.cts'],
				'vue-eslint-parser': ['.vue'],
			},
		},

		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/default': 'off',
		},
	},

	// Spread LAST. Before this, ZERO `vue/no-deprecated-*` rules were even
	// LISTED in the resolved config (not `off` — absent), so the Vue 2 idioms
	// this migration removes were invisible to lint.
	//
	// The preset already disables `vue/no-v-model-argument` and
	// `vue/no-v-for-template-key` itself; do not add local copies.
	...conductionVue3Fixes,

	// `eslint-config-prettier` LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the whole `vue/no-deprecated-*` family
	// spread just above is still present and still ON, because prettier has no
	// opinion about it. `indent` is now off HERE and enforced by prettier's
	// `useTabs: true` instead — the same tab, from the tool that also covers CSS
	// and SCSS, which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
