/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for how the Attachment and Catalogi entities fill themselves
 * from raw data: defaults, falsy and truthy values, arrays where an object
 * belongs, and the catalog's staff access. Run with RECORD_GOLDEN=1 to
 * rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { Attachment } from '../../src/entities/attachment/attachment.ts'
import { Catalogi } from '../../src/entities/catalogi/catalogi.ts'

const FIXTURE = path.resolve(__dirname, 'fixtures/entity-hydrate-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'

const DATE = new Date('2026-01-02T03:04:05Z')

const ATTACHMENTS: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		reference: '',
		title: '',
		labels: null,
		status: '',
		size: 0,
		anonymization: [],
		language: [],
		published: '',
		modified: 0,
	},
	full: {
		id: 42,
		reference: 'ref-1',
		title: 'Title',
		summary: 'Summary',
		description: 'Description',
		labels: ['a', 'b'],
		accessUrl: 'https://a',
		downloadUrl: 'https://d',
		status: 'Published',
		type: 'application/pdf',
		extension: 'pdf',
		size: '12 kB',
		anonymization: { anonymized: true, results: 'done' },
		language: { code: 'nl', level: 'B1' },
		versionOf: 'v0',
		hash: 'abc',
		published: DATE,
		modified: '2026-01-03',
		license: 'EUPL-1.2',
	},
	stringId: { id: 'uuid-1', anonymization: { anonymized: false } },
}

const CATALOGS: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		title: '',
		listed: 0,
		status: '',
		organization: null,
		registers: 'not-an-array',
		schemas: null,
		filters: null,
		hasWooSitemap: '',
		hasOoapi: 0,
		showWithheld: 'true',
		staffAccess: null,
	},
	full: {
		id: 7,
		title: 'Catalog',
		summary: 'S',
		description: 'D',
		image: 'img.png',
		listed: true,
		status: 'production',
		organization: 'org-1',
		registers: [1, 2],
		schemas: [3],
		filters: { a: 1 },
		slug: 'catalog',
		hasWooSitemap: true,
		hasOoapi: true,
		showWithheld: true,
		staffAccess: {
			restricted: true,
			readGroups: ['r'],
			editGroups: ['e'],
		},
	},
	staffAccessPartial: { staffAccess: { restricted: 'yes' } },
}

/**
 * Describe each own property of an entity, keeping the type that JSON loses.
 *
 * @param entity The entity
 * @return The properties, sorted by name
 */
function describeEntity(entity: object): Record<string, unknown> {
	const out: Record<string, unknown> = {}
	for (const key of Object.keys(entity).sort()) {
		const value = (entity as Record<string, unknown>)[key]
		if (value === undefined) {
			out[key] = '<undefined>'
		} else if (value instanceof Date) {
			out[key] = `<Date ${value.toISOString()}>`
		} else {
			out[key] = value
		}
	}
	return out
}

/**
 * Build an entity, or record what it threw.
 *
 * @param build The constructor call
 * @return The described entity or the error
 */
function attempt(build: () => object): unknown {
	try {
		return describeEntity(build())
	} catch (error) {
		return { thrown: (error as Error).constructor.name }
	}
}

describe('entity hydration (golden)', () => {
	it('matches the recorded entities', () => {
		const traces: Record<string, unknown> = {}
		for (const [name, data] of Object.entries(ATTACHMENTS)) {
			traces[`attachment ${name}`] = attempt(
				() => new Attachment(data as never),
			)
		}
		for (const [name, data] of Object.entries(CATALOGS)) {
			traces[`catalogi ${name}`] = attempt(() => new Catalogi(data as never))
		}
		traces['catalogi undefined'] = attempt(
			() => new Catalogi(undefined as never),
		)
		const actual = JSON.parse(JSON.stringify(traces))
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})

	it('copies the staff access groups instead of sharing them', () => {
		const readGroups = ['r']
		const catalog = new Catalogi({
			staffAccess: { restricted: true, readGroups, editGroups: [] },
		} as never)
		expect(catalog.staffAccess?.readGroups).toEqual(['r'])
		expect(catalog.staffAccess?.readGroups).not.toBe(readGroups)
	})
})
