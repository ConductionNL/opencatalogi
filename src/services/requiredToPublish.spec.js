/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the fields required to publish, read from the schema's lifecycle
 * (publication-required-at-publish REQ-PRP-003).
 */

jest.mock('../store/store.js', () => ({ objectStore: { availableSchemas: [] } }))

import {
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
