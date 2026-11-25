/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the fields required to publish, read from the schema's lifecycle
 * (publication-required-at-publish REQ-PRP-003).
 */

jest.mock('../store/store.js', () => ({ objectStore: { availableSchemas: [] } }))

import {
	clearAnsweredGaps,
	missingToPublish,
	refusalMessage,
	requiredToPublishFields,
} from './requiredToPublish.js'

const schema = {
	'x-openregister-lifecycle': {
		states: {
			in_review: {
				fields: {
					required: [
						{ fields: ['summary', 'description', 'organization'] },
					],
				},
			},
			published: {
				fields: {
					required: [
						{ fields: ['summary', 'description'] },
						{ fields: ['organization', 'summary'] },
					],
				},
			},
		},
	},
}

describe('requiredToPublishFields', () => {
	it('reads the published state of the lifecycle, in order and without duplicates', () => {
		expect(requiredToPublishFields(schema)).toEqual([
			'summary',
			'description',
			'organization',
		])
	})

	it('reads a lifecycle kept under the configuration', () => {
		expect(requiredToPublishFields({ configuration: schema })).toEqual([
			'summary',
			'description',
			'organization',
		])
	})

	it('is empty when the schema declares no rule', () => {
		expect(
			requiredToPublishFields({
				'x-openregister-lifecycle': { states: { draft: {} } },
			}),
		).toEqual([])
		expect(requiredToPublishFields(null)).toEqual([])
	})
})

describe('missingToPublish', () => {
	it('names the fields a publication leaves empty', () => {
		const publication = {
			title: 'Council papers for 15 October',
			summary: ' ',
			description: 'Agenda',
			organization: {},
		}
		expect(missingToPublish(publication, schema)).toEqual([
			'summary',
			'organization',
		])
	})

	it('names nothing for a complete publication or a schema without the rule', () => {
		expect(
			missingToPublish(
				{ summary: 'a', description: 'b', organization: 'org-1' },
				schema,
			),
		).toEqual([])
		expect(missingToPublish({ title: 'Only a title' }, {})).toEqual([])
	})

	it('resolves an inline schema from the publication when none is given', () => {
		expect(
			missingToPublish({
				'@self': { schema: { properties: {}, ...schema } },
				summary: 'a',
				description: 'b',
			}),
		).toEqual(['organization'])
	})
})

describe('refusalMessage', () => {
	it('prefers the message OpenRegister sent with its 422', () => {
		expect(
			refusalMessage({
				message: 'Request failed',
				response: {
					status: 422,
					data: { message: 'summary is required to publish' },
				},
			}),
		).toBe('summary is required to publish')
		expect(refusalMessage({ message: 'Network Error' })).toBe('Network Error')
	})
})

describe('clearAnsweredGaps', () => {
	it('drops the entry of a field the editor filled in and keeps the others', () => {
		const body = {
			summary: 'Besluit over de begroting',
			description: '',
			'@notSupplied': {
				summary: 'published-before-required',
				description: 'published-before-required',
			},
		}
		expect(clearAnsweredGaps(body)).toEqual({
			summary: 'Besluit over de begroting',
			description: '',
			'@notSupplied': { description: 'published-before-required' },
		})
		expect(body['@notSupplied'].summary).toBe('published-before-required')
	})

	it('removes the record when every recorded field is filled in', () => {
		expect(clearAnsweredGaps({
			organization: 'org-1',
			'@notSupplied': { organization: 'published-before-required' },
		})).toEqual({ organization: 'org-1' })
	})

	it('returns a body without a record as it is', () => {
		const body = { title: 'Raadsstukken' }
		expect(clearAnsweredGaps(body)).toBe(body)
	})
})
