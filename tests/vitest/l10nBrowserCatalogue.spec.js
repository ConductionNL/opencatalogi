/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The browser catalogue leaves out English entries that map a string to
 * itself (PERF-HANDOFF all apps): t() falls back to the key, so those
 * entries only cost every page ~200 KB. en.json stays complete, and the
 * l10n checks read their key set from en.json completed by en.js.
 *
 * @spec openspec/specs/l10n (PERF-HANDOFF all apps: drop English identity entries at build time)
 */

import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import vm from 'node:vm'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const ROOT = path.resolve(__dirname, '..', '..')
const L10N_DIR = path.join(ROOT, 'l10n')
const { browserEntries, renderJs } = require('../../scripts/build-l10n-js.js')
const { loadSourceCatalogue } = require('../../scripts/lib/l10n.js')

/**
 * Read the entries an `OC.L10N.register` file registers.
 *
 * @param {string} code the .js file body
 * @return {object} key -> translation
 */
function registered(code) {
	let captured = null
	const sandbox = { OC: { L10N: { register: (app, entries) => { captured = entries } } } }
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox)
	return captured
}

describe('browserEntries', () => {
	it('leaves English entries that equal their key out of en and en_*', () => {
		const translations = { Save: 'Save', Colour: 'Color' }
		expect(browserEntries('en', translations)).toEqual({ Colour: 'Color' })
		expect(browserEntries('en_GB', translations)).toEqual({ Colour: 'Color' })
	})

	it('keeps every entry of another locale, identical ones included', () => {
		const translations = { Save: 'Opslaan', Status: 'Status' }
		expect(browserEntries('nl', translations)).toEqual(translations)
	})
})

describe('renderJs', () => {
	it('renders an empty catalogue as a register call with no entries', () => {
		const code = renderJs('opencatalogi', {}, 'nplurals=2; plural=(n != 1);')
		expect(code).not.toMatch(/\{\n\n/)
		expect(registered(code)).toEqual({})
	})
})

describe('the shipped catalogues', () => {
	it('l10n/en.js carries no entry that maps a string to itself', () => {
		const entries = registered(fs.readFileSync(path.join(L10N_DIR, 'en.js'), 'utf8'))
		const identity = Object.keys(entries).filter((key) => entries[key] === key)
		expect(identity).toEqual([])
	})

	it('the source catalogue still holds every en.json key', () => {
		const json = JSON.parse(fs.readFileSync(path.join(L10N_DIR, 'en.json'), 'utf8')).translations
		const { app, translations } = loadSourceCatalogue(L10N_DIR)
		expect(app).toBe('opencatalogi')
		expect(Object.keys(translations).sort()).toEqual(Object.keys(json).sort())
	})
})
