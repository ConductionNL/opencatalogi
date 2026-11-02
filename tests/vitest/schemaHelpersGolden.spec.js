/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/schemaHelpers.js: whether a publication's
 * schema declares both publication date fields, for every way a publication
 * names its schema (inline, in @self, by id, uuid, slug or plain value) and
 * every store shape. Offline, no DOM. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, describe, expect, it, vi } from 'vitest'

const hooks = vi.hoisted(() => ({ store: {} }))

vi.mock('../../src/store/store.js', () => ({
	get objectStore() {
		return hooks.store
	},
}))

const { schemaHasPublicationDateFields } = await import('../../src/services/schemaHelpers.js')

const FIXTURE = resolve(process.cwd(), 'tests/vitest/fixtures/schema-helpers-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

const dated = { publicationDate: {}, depublicationDate: {} }
const SCHEMAS = [
	{ id: 7, uuid: 'u-7', slug: 'seven', properties: dated },
	{ id: 8, uuid: 'u-8', slug: 'eight', properties: { publicationDate: {} } },
	{ uuid: 'u-9', slug: 'nine', properties: dated },
	{ id: 10, slug: 'undefined', properties: dated },
]
const STORES = {
	schemas: { availableSchemas: SCHEMAS },
	noList: { availableSchemas: null },
	missing: null,
}
const REFS = {
	none: undefined,
	empty: '',
	zero: 0,
	idNumber: 7,
	idString: '7',
	uuid: 'u-7',
	slug: 'seven',
	partial: 8,
	uuidOnly: 'u-9',
	unknown: 'nope',
	objectId: { id: 7 },
	objectIdZero: { id: 0, uuid: 'u-9' },
	objectUuid: { uuid: 'u-9' },
	objectSlug: { slug: 'nine' },
	objectNullId: { id: null, uuid: 'u-7' },
	objectNone: {},
	objectUnknown: { id: 99 },
	inlineDated: { properties: dated },
	inlinePartial: { properties: { depublicationDate: {} } },
	inlineEmpty: { properties: null, id: 7 },
	inlineString: { properties: 'x' },
	undefinedSlug: 'undefined',
}
const PLACES = {
	self: (ref) => ({ '@self': { schema: ref } }),
	top: (ref) => ({ schema: ref }),
	both: (ref) => ({ '@self': { schema: ref }, schema: 'u-9' }),
	selfNull: (ref) => ({ '@self': { schema: null }, schema: ref }),
}

describe('schemaHasPublicationDateFields (golden)', () => {
	for (const storeName of Object.keys(STORES)) {
		it(`store ${storeName}`, () => {
			hooks.store = STORES[storeName]
			const out = { nullPublication: schemaHasPublicationDateFields(null), noSelf: schemaHasPublicationDateFields({}) }
			for (const [placeName, place] of Object.entries(PLACES)) {
				for (const [refName, ref] of Object.entries(REFS)) {
					out[`${placeName}/${refName}`] = schemaHasPublicationDateFields(place(ref))
				}
			}
			recorded[storeName] = out
			if (!RECORD) {
				expect(out).toEqual(expected[storeName])
			}
		})
	}
})
