/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications page (src/manifest.json) opens newest first and sorts server-side on every column it shows on the publication pair.
 * CnIndexPage turns a column key straight into OpenRegister's `_order`,
 * and OpenRegister silently ignores a key it cannot map,
 * so each key is checked against what it can sort on.
 */

import { columnsFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SETTINGS = path.join(ROOT, 'lib/Settings')

// Metadata keys OpenRegister's MagicSearchHandler::applySorting maps to a column.
const SORTABLE_METADATA = /^@self\.(created|updated|name|description|summary|uuid)$/
const DIRECT_METADATA = [
	'_created',
	'_updated',
	'_name',
	'_description',
	'_summary',
	'_uuid',
]

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

const page = readJson(path.join(ROOT, 'src/manifest.json')).pages.find(
	(p) => p.id === 'Publications',
)
const config = page.config
// The columns name publication properties, so they only reach CnIndexPage on the publication pair.
const columns = config.publicationPairConfig.columns
const keyOf = (col) => (typeof col === 'string' ? col : col.key)

const baseProperties = readJson(path.join(SETTINGS, 'publication_register.json'))
	.components.schemas.publication.properties

/**
 * The publication schema properties as installed: the base register with every register.d fragment merged on top.
 *
 * @return {{properties: object, fragmentOnly: string[]}} Merged properties and the keys only fragments add.
 */
function installedSchema() {
	const properties = { ...baseProperties }
	const fragmentOnly = []
	const dir = path.join(SETTINGS, 'register.d')
	const fragments = fs.readdirSync(dir).filter((n) => n.endsWith('.json'))
	for (const name of fragments.sort()) {
		const schemas = readJson(path.join(dir, name)).components?.schemas
		const extra = schemas?.publication?.properties || {}
		for (const [key, prop] of Object.entries(extra)) {
			if (!(key in baseProperties) && !fragmentOnly.includes(key)) {
				fragmentOnly.push(key)
			}
			properties[key] = { ...(properties[key] || {}), ...prop }
		}
	}
	return { properties, fragmentOnly }
}

const { properties, fragmentOnly } = installedSchema()

/**
 * Whether OpenRegister can order by this key.
 *
 * @param {string} key A column or sort key.
 * @return {boolean} True when the key reaches the ORDER BY.
 */
function isSortableKey(key) {
	return (
		SORTABLE_METADATA.test(key)
		|| DIRECT_METADATA.includes(key)
		|| key in properties
	)
}

describe('Publications page default sort', () => {
	it('opens newest first with a hidden uuid tie-break', () => {
		expect(config.sortKeys).toEqual([
			{ key: '@self.created', order: 'desc' },
			{ key: '_uuid', order: 'asc' },
		])
		expect(columns.map(keyOf)).not.toContain('_uuid')
	})
})

describe('Publications page columns', () => {
	it('leads with Title, Status, Created and Updated', () => {
		expect(columns.slice(0, 4).map(keyOf)).toEqual([
			'title',
			'status',
			'@self.created',
			'@self.updated',
		])
	})

	it('opts every object column in to sorting', () => {
		// Bare strings are resolved by the library, which marks them sortable.
		const objects = columns.filter((c) => typeof c === 'object')
		for (const col of objects) {
			expect(col.sortable, col.key).toBe(true)
		}
	})

	it('keeps every schema column, in the schema-derived order, after the lead columns', () => {
		const lead = ['title', 'status']
		const rest = columns.slice(4).map(keyOf)
		const expected = columnsFromSchema({ properties })
			.map((col) => col.key)
			.filter((key) => !lead.includes(key))
		expect(rest).toEqual(expected)
	})

	it('labels fragment-only properties so an install without them still names the column', () => {
		const fragmentColumns = columns.filter((col) =>
			fragmentOnly.includes(keyOf(col)),
		)
		for (const col of fragmentColumns) {
			expect(col, keyOf(col)).toMatchObject({
				label: properties[keyOf(col)].title,
			})
		}
	})
})

describe('Publications page sort-key binding', () => {
	it('binds every column to a key OpenRegister can order by', () => {
		for (const col of columns) {
			expect(isSortableKey(keyOf(col)), keyOf(col)).toBe(true)
		}
	})

	it('binds every default sort key to a key OpenRegister can order by', () => {
		for (const { key } of config.sortKeys) {
			expect(isSortableKey(key), key).toBe(true)
		}
	})

	it('rejects a bare metadata key, which OpenRegister ignores', () => {
		expect(isSortableKey('created')).toBe(false)
		expect(isSortableKey('updated')).toBe(false)
	})
})
