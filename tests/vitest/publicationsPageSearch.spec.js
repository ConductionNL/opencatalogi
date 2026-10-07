/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications index page (src/manifest.json) searches server-side through CnIndexPage's inline search,
 * which sends the term to OpenRegister as `_search`.
 * OpenRegister matches that term against every plain, non-date, unencrypted string property of the schema,
 * so the fields a user expects to find a publication by are checked against that rule.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SETTINGS = path.join(ROOT, 'lib/Settings')
const L10N = path.join(ROOT, 'l10n')

// Formats MagicSearchHandler::applyFullTextSearch leaves out of the free-text scan.
const DATE_FORMATS = ['date', 'date-time', 'time']

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

const config = readJson(path.join(ROOT, 'src/manifest.json')).pages.find(
	(p) => p.id === 'Publications',
).config

/**
 * The publication schema properties as installed: the base register with every register.d fragment merged on top.
 *
 * @return {object} Merged properties keyed by name.
 */
function installedProperties() {
	const properties = {
		...readJson(path.join(SETTINGS, 'publication_register.json')).components
			.schemas.publication.properties,
	}
	const dir = path.join(SETTINGS, 'register.d')
	const fragments = fs.readdirSync(dir).filter((n) => n.endsWith('.json'))
	for (const name of fragments.sort()) {
		const schemas = readJson(path.join(dir, name)).components?.schemas
		const extra = schemas?.publication?.properties || {}
		for (const [key, prop] of Object.entries(extra)) {
			properties[key] = { ...(properties[key] || {}), ...prop }
		}
	}
	return properties
}

const properties = installedProperties()

/**
 * Whether OpenRegister's free-text search reads this property.
 *
 * @param {object} prop A schema property definition.
 * @return {boolean} True when `_search` matches against its value.
 */
function isFreeTextSearchable(prop) {
	return (
		prop?.type === 'string'
		&& !DATE_FORMATS.includes(prop.format)
		&& prop['x-openregister-encrypted'] !== true
	)
}

describe('Publications page search', () => {
	it('enables the inline server-side search', () => {
		expect(config.inlineSearch).toBe(true)
	})

	it('opts into showCountWithSearch', () => {
		expect(config.showCountWithSearch).toBe(true)
	})

	it('leaves the placeholder to the library, which translates its default', () => {
		expect(config).not.toHaveProperty('searchPlaceholder')
	})

	it('names publications in the empty state, with a key every locale translates', () => {
		expect(config.emptyText).toBe('No publications found')
		const catalogues = fs
			.readdirSync(L10N)
			.filter((n) => /^[a-z]{2,3}(_[A-Z]{2})?\.json$/.test(n))
		expect(catalogues.length).toBeGreaterThan(1)
		for (const name of catalogues) {
			const { translations } = readJson(path.join(L10N, name))
			expect(translations[config.emptyText], name).toMatch(/\S/)
		}
	})
})

describe('Publication schema free-text search fields', () => {
	it.each(['title', 'summary', 'description'])(
		'%s is a plain string OpenRegister searches',
		(key) => {
			expect(isFreeTextSearchable(properties[key]), key).toBe(true)
		},
	)

	it('rejects a date-formatted string, which the search skips', () => {
		expect(isFreeTextSearchable(properties.publicationDate)).toBe(false)
	})
})
