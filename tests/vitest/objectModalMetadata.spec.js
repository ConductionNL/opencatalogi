/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for objectMetadata: the metadata rows of an object, its display
 * and schema names, and how a schema reference resolves against the listed
 * schemas (Vitest offline suite).
 */
import { describe, expect, it } from 'vitest'
import {
	findSchemaByAnyId,
	formatDateOr,
	isBlankValue,
	lockLabel,
	metadataRows,
	objectDisplayName,
	objectSchemaName,
	referencedSchema,
	referencedSchemaProperties,
	refLabel,
} from '../../src/modals/object/objectMetadata.js'

const DATE = '2026-01-02T03:04:05Z'
const LOCAL = new Date(DATE).toLocaleString()

describe('formatDateOr', () => {
	it('formats a date in the locale, or gives the fallback', () => {
		expect(formatDateOr(DATE, '-')).toBe(LOCAL)
		expect(formatDateOr(null, '-')).toBe('-')
	})
})

describe('refLabel', () => {
	const available = [{ id: 1, title: 'Listed' }]

	it('says Not set without a reference', () => {
		expect(refLabel(null, available)).toBe('Not set')
	})

	it('labels an embedded reference by title, name, id or itself', () => {
		expect(refLabel({ title: 'T', name: 'N' }, available)).toBe('T')
		expect(refLabel({ name: 'N', id: 3 }, available)).toBe('N')
		expect(refLabel({ id: 3 }, available)).toBe(3)
	})

	it('labels a bare id by the listed title, or the id itself', () => {
		expect(refLabel(1, available)).toBe('Listed')
		expect(refLabel(2, available)).toBe(2)
	})
})

describe('lockLabel', () => {
	it('says not locked, or just locked for a flag', () => {
		expect(lockLabel(null)).toBe('Not locked')
		expect(lockLabel(true)).toBe('Locked')
	})

	it('describes who locked it, when and in which process', () => {
		expect(lockLabel({ lockedBy: 'alice', lockedAt: DATE, process: 'import' })).toBe(`Locked by alice at ${LOCAL} (import)`)
		expect(lockLabel({})).toBe('Locked by Unknown user at Unknown time')
	})
})

describe('metadataRows', () => {
	it('lists the object metadata with each fallback', () => {
		expect(metadataRows({}, { registers: [], schemas: [] })).toEqual([
			['ID', 'Not set'],
			['Version', 'Not set'],
			['Register', 'Not set'],
			['Schema', 'Not set'],
			['Locked', 'Not locked'],
			['Created', 'Not set'],
			['Updated', 'Not set'],
			['Published', 'Not published'],
			['Depublished', 'Not depublished'],
		])
	})

	it('fills the rows from @self and the listed registers and schemas', () => {
		const rows = metadataRows(
			{ id: 'o1', '@self': { version: '1.2', register: 1, schema: 2, locked: true, created: DATE, published: DATE } },
			{ registers: [{ id: 1, title: 'Reg' }], schemas: [{ id: 2, title: 'Sch' }] },
		)

		expect(Object.fromEntries(rows)).toMatchObject({
			ID: 'o1',
			Version: '1.2',
			Register: 'Reg',
			Schema: 'Sch',
			Locked: 'Locked',
			Created: LOCAL,
			Published: LOCAL,
		})
	})
})

describe('objectDisplayName', () => {
	it('prefers @self.name, then name, title, id, then Untitled', () => {
		expect(objectDisplayName({ '@self': { name: 'S' }, name: 'N' })).toBe('S')
		expect(objectDisplayName({ name: 'N', title: 'T' })).toBe('N')
		expect(objectDisplayName({ title: 'T', id: 1 })).toBe('T')
		expect(objectDisplayName({ id: 1 })).toBe(1)
		expect(objectDisplayName({})).toBe('Untitled')
	})
})

describe('objectSchemaName', () => {
	const schemas = [{ id: 4, title: 'Besluit' }, { id: 5, name: 'Nota' }]

	it('names an embedded schema by title, name, id or Publication', () => {
		expect(objectSchemaName({ schema: { title: 'T' } }, schemas)).toBe('T')
		expect(objectSchemaName({ schema: { name: 'N' } }, schemas)).toBe('N')
		expect(objectSchemaName({ schema: { id: 9 } }, schemas)).toBe(9)
		expect(objectSchemaName({ schema: {} }, schemas)).toBe('Publication')
	})

	it('names a listed schema id, from the object or @self, across number and string', () => {
		expect(objectSchemaName({ schema: '4' }, schemas)).toBe('Besluit')
		expect(objectSchemaName({ '@self': { schema: 5 } }, schemas)).toBe('Nota')
		expect(objectSchemaName({ schema: 7 }, schemas)).toBe('7')
	})

	it('says Publication without a schema', () => {
		expect(objectSchemaName({ schema: '' }, schemas)).toBe('Publication')
		expect(objectSchemaName({}, schemas)).toBe('Publication')
	})
})

describe('isBlankValue', () => {
	it('is true only for null, undefined and the empty string', () => {
		expect([null, undefined, '', 0, false, ' '].map(isBlankValue)).toEqual([true, true, true, false, false, false])
	})
})

describe('schema references', () => {
	const schemas = [
		{ id: 1, uuid: 'u-1', slug: 'one', properties: { a: {} } },
		{ id: 2, uuid: 'u-2', slug: 'two', properties: { b: {} } },
	]

	it('use an embedded schema with properties as it is', () => {
		const embedded = { id: 9, properties: { c: {} } }

		expect(referencedSchema(embedded, schemas)).toBe(embedded)
		expect(referencedSchemaProperties(embedded, schemas)).toBe(embedded.properties)
	})

	it('resolve a reference by id, uuid or slug, as text', () => {
		expect(referencedSchema('1', schemas)).toBe(schemas[0])
		expect(referencedSchema('u-2', schemas)).toBe(schemas[1])
		expect(referencedSchema({ slug: 'two' }, schemas)).toBe(schemas[1])
		expect(referencedSchemaProperties(2, schemas)).toEqual({ b: {} })
		expect(referencedSchemaProperties({ uuid: 'u-1' }, schemas)).toEqual({ a: {} })
		expect(findSchemaByAnyId(schemas, 'one')).toBe(schemas[0])
	})

	it('resolve nothing for an empty or unknown reference', () => {
		expect(referencedSchema(null, schemas)).toBeNull()
		expect(referencedSchema({}, schemas)).toBeNull()
		expect(referencedSchema('nope', schemas)).toBeNull()
		expect(referencedSchemaProperties(null, schemas)).toEqual({})
		expect(referencedSchemaProperties({ slug: 'one' }, schemas)).toEqual({})
		expect(referencedSchemaProperties('nope', schemas)).toEqual({})
		expect(findSchemaByAnyId(schemas, 'nope')).toBeUndefined()
	})
})
