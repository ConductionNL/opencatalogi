/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterization tests for how the Publication entity fills itself from
 * raw data: the defaults, the featured flag the backend sends as a string,
 * and an array where an object belongs counting as missing.
 */

import { describe, expect, it } from 'vitest'
import { Publication } from '../../src/entities/publication/publication.ts'

describe('Publication hydration', () => {
	it('fills every missing field with its default', () => {
		const publication = new Publication({} as never)

		expect(JSON.parse(JSON.stringify(publication))).toEqual({
			title: '',
			summary: '',
			description: '',
			reference: '',
			image: '',
			organization: '',
			category: '',
			portal: '',
			featured: false,
			source: '',
			status: 'Concept',
			themes: [],
			data: {},
			anonymization: { anonymized: false, results: '' },
			language: { code: '', level: '' },
			published: '',
			modified: '',
			license: '',
			archive: { date: '' },
			geo: { type: 'Point', coordinates: [0, 0] },
			'@self': {},
			catalog: {},
			register: null,
			schema: null,
		})
		expect(Object.keys(publication)).toEqual([
			'id',
			'title',
			'summary',
			'description',
			'reference',
			'image',
			'organization',
			'category',
			'portal',
			'featured',
			'source',
			'status',
			'themes',
			'data',
			'anonymization',
			'language',
			'published',
			'modified',
			'license',
			'archive',
			'geo',
			'@self',
			'catalog',
			'register',
			'schema',
		])
	})

	it('keeps given values and turns the id into a string', () => {
		const publication = new Publication({
			id: 7,
			title: 'T',
			status: 'Published',
			themes: ['a'],
			data: { k: 1 },
			geo: { type: 'Point', coordinates: [5, 52] },
			'@self': { id: 's' },
			catalog: { id: 'c' },
			register: 3,
			schema: 4,
		} as never)

		expect(publication.id).toBe('7')
		expect(publication.title).toBe('T')
		expect(publication.status).toBe('Published')
		expect(publication.themes).toEqual(['a'])
		expect(publication.data).toEqual({ k: 1 })
		expect(publication.geo).toEqual({ type: 'Point', coordinates: [5, 52] })
		expect(publication['@self']).toEqual({ id: 's' })
		expect(publication.catalog).toEqual({ id: 'c' })
		expect(publication.register).toBe(3)
		expect(publication.schema).toBe(4)
	})

	it.each([
		[true, true],
		[false, false],
		['1', true],
		['', false],
		['0', false],
		['abc', false],
		[1, false],
		[undefined, false],
	])('reads featured %j as %s', (featured, expected) => {
		expect(new Publication({ featured } as never).featured).toBe(expected)
	})

	it('treats an array where an object belongs as missing', () => {
		const publication = new Publication({
			data: [],
			anonymization: [],
			language: [],
			archive: [],
			geo: [],
			'@self': [],
			catalog: [],
		} as never)

		expect(publication.data).toEqual({})
		expect(publication.anonymization).toEqual({ anonymized: false, results: '' })
		expect(publication.language).toEqual({ code: '', level: '' })
		expect(publication.archive).toEqual({ date: '' })
		expect(publication.geo).toEqual({ type: 'Point', coordinates: [0, 0] })
		expect(publication['@self']).toEqual({})
		expect(publication.catalog).toEqual({})
	})
})
