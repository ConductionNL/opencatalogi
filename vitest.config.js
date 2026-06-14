/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for OpenCatalogi frontend unit tests.
 *
 * This OFFLINE suite (no Nextcloud runtime) targets PURE logic that the
 * rendered UI exercises end-to-end but never asserts exactly:
 *   • src/services/*  — ISO-date validation, publication-status derivation,
 *     publication-type-id extraction, Zod-error mapping, schema-field gating.
 *   • a couple of synchronous Pinia store actions/getters (catalog view-mode
 *     + pagination) driven through a real Pinia instance.
 *
 * The pure services need no DOM, so the environment is `node`. The
 * @conduction/nextcloud-vue and @nextcloud/* runtime packages are aliased to
 * lightweight stubs so importing a store module doesn't drag in the NC shell.
 *
 * The existing Jest suite (jest.config.js + *.spec.{js,ts}) is UNTOUCHED — it
 * covers the async OR-backed store actions against jsdom; this Vitest suite is
 * the offline pure-logic complement. Vitest only picks up tests/vitest/**.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'src/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/conduction-nextcloud-vue.js'),
			},
			{
				find: /^@nextcloud\/l10n$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-l10n.js'),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-axios.js'),
			},
		],
	},
}
