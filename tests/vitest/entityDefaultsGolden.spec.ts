/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for how the Listing, Theme, PublicationType and Publication
 * entities fill themselves from raw data: defaults, falsy and truthy values,
 * arrays where an object belongs, missing data, and the order in which the
 * fields land on the entity. Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { Listing } from '../../src/entities/listing/listing.ts'
import { Publication } from '../../src/entities/publication/publication.ts'
import { PublicationType } from '../../src/entities/publicationType/publicationType.ts'
import { Theme } from '../../src/entities/theme/theme.ts'

const FIXTURE = path.resolve(__dirname, 'fixtures/entity-defaults-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'

const DATE = new Date('2026-01-02T03:04:05Z')

const LISTINGS: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		catalogusId: '',
		title: '',
		metadata: null,
		statusCode: 0,
		lastSync: '',
		available: false,
		default: 0,
		organization: null,
		publicationTypes: '',
	},
	full: {
		id: 12,
		catalogusId: 'cat-1',
		title: 'Listing',
		summary: 'S',
		description: 'D',
		search: 'https://s',
		publications: 'https://p',
		directory: 'https://d',
		metadata: ['m'],
		status: 'ok',
		statusCode: 200,
		lastSync: DATE,
		available: true,
		default: true,
		organization: { id: 'org' },
		publicationTypes: [{ id: 1 }],
	},
}

const THEMES: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		title: '',
		isExternal: 0,
		sort: 0,
	},
	nullSort: { sort: null, isExternal: '' },
	full: {
		id: 'theme-1',
		title: 'Theme',
		summary: 'S',
		description: 'D',
		image: 'img.png',
		content: 'C',
		link: 'https://l',
		url: 'https://u',
		icon: 'icon',
		isExternal: true,
		sort: 3,
	},
}

const PUBLICATION_TYPES: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		title: '',
		required: null,
		properties: [],
		archive: [],
		source: '',
	},
	full: {
		id: 5,
		title: 'Type',
		description: 'D',
		summary: 'S',
		version: '1.0',
		required: ['a'],
		properties: {
			a: { title: 'A', minimum: null, maximum: 4, minLength: null },
			b: { title: 'B' },
		},
		archive: { valuation: 'b', class: 2 },
		source: 'https://src',
	},
}

const PUBLICATIONS: Record<string, unknown> = {
	empty: {},
	falsy: {
		id: 0,
		title: '',
		status: '',
		featured: '',
		themes: null,
		register: 0,
		schema: 0,
	},
	featuredString: { featured: '1', status: 'Published' },
	full: {
		id: 9,
		title: 'Publication',
		summary: 'S',
		description: 'D',
		reference: 'R',
		image: 'img.png',
		organization: 'org',
		category: 'cat',
		portal: 'https://portal',
		featured: true,
		source: 'src',
		status: 'Withdrawn',
		themes: ['t'],
		data: { a: 1 },
		license: 'EUPL-1.2',
		published: DATE,
		register: 1,
		schema: 2,
	},
}

/**
 * Describe each own property of an entity in the order it was set, keeping
 * the type that JSON loses.
 *
 * @param entity The entity
 * @return The keys in order and the values
 */
function describeEntity(entity: object): Record<string, unknown> {
	const values: Record<string, unknown> = {}
	for (const key of Object.keys(entity)) {
		const value = (entity as Record<string, unknown>)[key]
		if (value === undefined) {
			values[key] = '<undefined>'
		} else if (value instanceof Date) {
			values[key] = `<Date ${value.toISOString()}>`
		} else {
			values[key] = value
		}
	}
	return { keys: Object.keys(entity), values }
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

const ENTITIES: Array<[string, Record<string, unknown>, (data: never) => object]> = [
	['listing', LISTINGS, (data) => new Listing(data)],
	['theme', THEMES, (data) => new Theme(data)],
	['publicationType', PUBLICATION_TYPES, (data) => new PublicationType(data)],
	['publication', PUBLICATIONS, (data) => new Publication(data)],
]

describe('entity defaults (golden)', () => {
	it('matches the recorded entities', () => {
		const traces: Record<string, unknown> = {}
		for (const [entity, cases, build] of ENTITIES) {
			for (const [name, data] of Object.entries(cases)) {
				traces[`${entity} ${name}`] = attempt(() => build(data as never))
			}
			traces[`${entity} undefined`] = attempt(() => build(undefined as never))
		}
		const actual = JSON.parse(JSON.stringify(traces))
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})

	it('gives every entity its own default lists and objects', () => {
		const first = new Listing({} as never)
		const second = new Listing({} as never)
		expect(first.metadata).not.toBe(second.metadata)
		expect(first.publicationTypes).not.toBe(second.publicationTypes)

		const typeA = new PublicationType({} as never)
		const typeB = new PublicationType({} as never)
		expect(typeA.required).not.toBe(typeB.required)
		expect(typeA.properties).not.toBe(typeB.properties)
		expect(typeA.archive).not.toBe(typeB.archive)
	})

	it('keeps the data it was given by reference', () => {
		const metadata = ['m']
		expect(new Listing({ metadata } as never).metadata).toBe(metadata)
		const properties = { a: { title: 'A', minimum: null } }
		const type = new PublicationType({ properties } as never)
		expect(type.properties).toBe(properties)
		expect(properties.a.minimum).toBe(0)
	})
})
