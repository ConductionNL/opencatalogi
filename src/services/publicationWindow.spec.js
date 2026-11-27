/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the publication window of an object of any schema: its date
 * properties (English, else Dutch), its status for each combination of P and
 * D, and what publishing or depublishing it writes. Today is 2026-06-15.
 */

import {
	canDepublish,
	canPublish,
	depublishPatch,
	publicationDateFields,
	publicationWindowStatus,
	publishPatch,
} from './publicationWindow.js'

jest.mock('../store/store.js', () => ({
	objectStore: {
		availableSchemas: [
			{ id: 24, properties: { publicationDate: {}, depublicationDate: {} } },
		],
	},
}))

const TODAY = '2026-06-15'
const ENGLISH = { properties: { publicationDate: {}, depublicationDate: {} } }
const DUTCH = { properties: { publicatiedatum: {}, depublicatiedatum: {} } }
const PUBLICATION_ONLY = { properties: { publicatiedatum: {} } }

/**
 * An object of a schema with these dates.
 *
 * @param {object} dates Its date properties.
 * @param {object} [schema] Its schema, inline.
 * @return {object} The object.
 */
function object(dates = {}, schema = ENGLISH) {
	return { id: 'o1', ...dates, '@self': { register: 1, schema } }
}

describe('publicationDateFields', () => {
	it('prefers the English names, falls back to the Dutch ones, and gives null for neither', () => {
		expect(publicationDateFields(object())).toEqual({ publication: 'publicationDate', depublication: 'depublicationDate' })
		expect(publicationDateFields(object({}, DUTCH))).toEqual({ publication: 'publicatiedatum', depublication: 'depublicatiedatum' })
		expect(publicationDateFields(object({}, PUBLICATION_ONLY))).toEqual({ publication: 'publicatiedatum', depublication: null })
		expect(publicationDateFields(object({}, { properties: { title: {} } }))).toEqual({ publication: null, depublication: null })
		expect(publicationDateFields(null)).toEqual({ publication: null, depublication: null })
	})

	it('reads a schema the row only references by id', () => {
		const row = { '@self': { register: 1, schema: 24 } }
		expect(publicationDateFields(row).depublication).toBe('depublicationDate')
	})
})

describe('publicationWindowStatus', () => {
	it.each([
		[1, {}, 'concept'],
		[2, { depublicationDate: '2026-07-01' }, 'concept'],
		[3, { depublicationDate: '2026-06-01' }, 'depublished'],
		[4, { publicationDate: '2026-07-01' }, 'scheduled'],
		[5, { publicationDate: '2026-07-01', depublicationDate: '2026-08-01' }, 'scheduled'],
		[6, { publicationDate: '2026-07-01', depublicationDate: '2026-06-20' }, 'scheduled'],
		[7, { publicationDate: '2026-06-01' }, 'published'],
		[8, { publicationDate: '2026-06-01', depublicationDate: '2026-07-01' }, 'published'],
		[9, { publicationDate: '2026-06-01', depublicationDate: '2026-06-10' }, 'depublished'],
	])('matrix row %i is %s', (row, dates, status) => {
		expect(publicationWindowStatus(object(dates), TODAY)).toBe(status)
	})

	it('counts today as reached, also for a date with a time', () => {
		expect(publicationWindowStatus(object({ publicationDate: TODAY }), TODAY)).toBe('published')
		expect(publicationWindowStatus(object({ publicationDate: '2026-06-01', depublicationDate: `${TODAY}T23:00:00Z` }), TODAY)).toBe('depublished')
	})

	it('reads the Dutch properties of a Dutch schema', () => {
		expect(publicationWindowStatus(object({ publicatiedatum: '2026-06-01' }, DUTCH), TODAY)).toBe('published')
		expect(publicationWindowStatus(object({ publicationDate: '2026-06-01' }, DUTCH), TODAY)).toBe('concept')
	})
})

describe('depublishPatch', () => {
	it('sets the depublication date of a published object', () => {
		expect(depublishPatch(object({ publicationDate: '2026-06-01' }), TODAY, TODAY)).toEqual({ depublicationDate: TODAY })
		expect(depublishPatch(object({ publicationDate: '2026-06-01', depublicationDate: '2026-07-01' }), TODAY, TODAY)).toEqual({ depublicationDate: TODAY })
		expect(depublishPatch(object({ publicatiedatum: '2026-06-01' }, DUTCH), '2026-09-30', TODAY)).toEqual({ depublicatiedatum: '2026-09-30' })
	})

	it('takes a scheduled object back to concept unless the date falls after its publication date', () => {
		const scheduled = object({ publicationDate: '2026-07-01' })
		expect(depublishPatch(scheduled, TODAY, TODAY)).toEqual({ publicationDate: null })
		expect(depublishPatch(scheduled, '2026-07-01', TODAY)).toEqual({ publicationDate: null })
		expect(depublishPatch(scheduled, '2026-07-02', TODAY)).toEqual({ depublicationDate: '2026-07-02' })
	})

	it('leaves concepts, depublished objects and schemas without a depublication date alone', () => {
		expect(depublishPatch(object(), TODAY, TODAY)).toBeNull()
		expect(depublishPatch(object({ depublicationDate: '2026-07-01' }), TODAY, TODAY)).toBeNull()
		expect(depublishPatch(object({ publicationDate: '2026-06-01', depublicationDate: '2026-06-10' }), TODAY, TODAY)).toBeNull()
		expect(depublishPatch(object({ publicatiedatum: '2026-06-01' }, PUBLICATION_ONLY), TODAY, TODAY)).toBeNull()
	})
})

describe('publishPatch', () => {
	it('sets the publication date and clears the depublication date the schema has', () => {
		expect(publishPatch(object(), TODAY)).toEqual({ publicationDate: TODAY, depublicationDate: null })
		expect(publishPatch(object({}, PUBLICATION_ONLY), TODAY)).toEqual({ publicatiedatum: TODAY })
		expect(publishPatch(object({}, DUTCH), null)).toEqual({ depublicatiedatum: null })
		expect(publishPatch(object({}, { properties: {} }), TODAY)).toBeNull()
	})
})

describe('canPublish and canDepublish', () => {
	it('offer Publish until an object is published, and Depublish while it is published or scheduled', () => {
		const cases = [
			[object(), true, false],
			[object({ publicationDate: '2026-07-01' }), true, true],
			[object({ publicationDate: '2026-06-01' }), false, true],
			[object({ publicationDate: '2026-06-01', depublicationDate: '2026-06-10' }), true, false],
			[object({ publicatiedatum: '2026-06-01' }, PUBLICATION_ONLY), false, false],
			[object({}, PUBLICATION_ONLY), true, false],
			[object({}, { properties: {} }), false, false],
		]
		for (const [row, publish, depublish] of cases) {
			expect([canPublish(row, TODAY), canDepublish(row, TODAY)]).toEqual([publish, depublish])
		}
	})
})
