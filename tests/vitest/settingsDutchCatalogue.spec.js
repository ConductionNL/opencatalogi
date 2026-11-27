/**
 * Every string of the admin settings page has a Dutch translation.
 *
 * The settings page is the administrator's first screen; a key without a
 * Dutch value falls back to English there, which no gate reports because
 * the other locales of this app are not complete either.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-12.2
 */

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { loadJsTranslations } = require('../../scripts/lib/l10n.js')

const ROOT = path.resolve(__dirname, '../..')
const PAGES = ['src/views/settings/Settings.vue', 'src/views/settings/AdminRoot.vue']

/**
 * The literal keys of every `t('opencatalogi', '...')` call in a file.
 *
 * @param {string} file - path relative to the repository root
 * @return {string[]} the keys
 */
function keysIn(file) {
	const source = readFileSync(path.join(ROOT, file), 'utf8')
	const keys = []
	for (const match of source.matchAll(/\bt\(\s*['"]opencatalogi['"]\s*,\s*(['"])((?:\\.|(?!\1).)*)\1/g)) {
		keys.push(match[2].replace(/\\(.)/g, '$1'))
	}
	return keys
}

describe('the admin settings page in Dutch', () => {
	const json = JSON.parse(readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
	const browser = loadJsTranslations(path.join(ROOT, 'l10n/nl.js')).translations
	const keys = [...new Set(PAGES.flatMap(keysIn))]

	it('finds the page strings', () => {
		expect(keys.length).toBeGreaterThan(50)
		expect(keys).toContain('Run check')
	})

	it.each(keys)('translates %s in the source and the browser catalogue', (key) => {
		expect(typeof json[key]).toBe('string')
		expect(json[key]).not.toBe('')
		expect(browser[key]).toBe(json[key])
	})
})
