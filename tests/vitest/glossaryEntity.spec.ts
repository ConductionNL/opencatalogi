/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Glossary entity: how it fills itself from raw data and
 * what its validation accepts and refuses, plus the glossary and organization
 * mock factories other tests build on (Vitest offline suite).
 */

import { describe, expect, it } from 'vitest'
import { mockGlossary, mockGlossaryData } from '../../src/entities/glossary/glossary.mock.ts'
import { Glossary } from '../../src/entities/glossary/glossary.ts'
import { mockOrganizationData, mockOrganizations } from '../../src/entities/organization/organization.mock.ts'
import { Organization } from '../../src/entities/organization/organization.ts'

/**
 * The paths of the issues a validation reported.
 *
 * @param glossary The glossary to validate
 * @return The issue paths, joined with dots
 */
function issuePaths(glossary: Glossary): string[] {
	const result = glossary.validate()
	return result.success ? [] : result.error.issues.map((issue) => issue.path.join('.'))
}

describe('Glossary hydration', () => {
	it('keeps every field it is given and turns the id into a string', () => {
		const glossary = new Glossary({
			id: 7 as unknown as string,
			title: 'API',
			summary: 'Short',
			description: 'Long',
			externalLink: 'https://example.org',
			keywords: ['a', 'b'],
		})

		expect({ ...glossary }).toEqual({
			id: '7',
			title: 'API',
			summary: 'Short',
			description: 'Long',
			externalLink: 'https://example.org',
			keywords: ['a', 'b'],
		})
	})

	it('fills empty strings and an empty keyword list for what is missing', () => {
		const glossary = new Glossary({} as never)

		expect({ ...glossary }).toEqual({ id: '', title: '', summary: '', description: '', externalLink: '', keywords: [] })
	})

	it('drops keywords that are not a list', () => {
		expect(new Glossary({ keywords: 'api' } as never).keywords).toEqual([])
	})
})

describe('Glossary validation', () => {
	const valid = { id: '1', title: 'API', summary: '', description: '', externalLink: '', keywords: [] }

	it('accepts a titled entry with or without an http(s) link', () => {
		expect(new Glossary(valid).validate().success).toBe(true)
		expect(new Glossary({ ...valid, externalLink: 'http://x.nl/a' }).validate().success).toBe(true)
	})

	it('requires a title', () => {
		const result = new Glossary({ ...valid, title: '' }).validate()

		expect(result.success).toBe(false)
		expect(result.error?.issues[0].message).toBe('is verplicht')
		expect(result.error?.issues[0].path).toEqual(['title'])
	})

	it('refuses a link that is not http(s)', () => {
		const result = new Glossary({ ...valid, externalLink: 'ftp://x.nl' }).validate()

		expect(result.success).toBe(false)
		expect(result.error?.issues[0].message).toBe('moet een geldige URL zijn (optioneel)')
	})

	it('caps the lengths of title, summary, description and link', () => {
		const glossary = new Glossary({
			...valid,
			title: 'x'.repeat(256),
			summary: 'x'.repeat(256),
			description: 'x'.repeat(2556),
			externalLink: 'https://' + 'x'.repeat(250),
		})

		expect(issuePaths(glossary)).toEqual(['title', 'summary', 'description', 'externalLink'])
	})

	it('accepts the limits themselves', () => {
		const glossary = new Glossary({
			...valid,
			title: 'x'.repeat(255),
			summary: 'x'.repeat(255),
			description: 'x'.repeat(2555),
		})

		expect(issuePaths(glossary)).toEqual([])
	})
})

describe('mock factories', () => {
	it('build a Glossary per mock entry, of which only the invalid link fails', () => {
		const glossaries = mockGlossary()

		expect(glossaries).toHaveLength(mockGlossaryData().length)
		glossaries.forEach((glossary) => expect(glossary).toBeInstanceOf(Glossary))
		expect(glossaries.map((glossary) => glossary.validate().success)).toEqual([true, true, false])
	})

	it('build from given data', () => {
		const [only] = mockGlossary([{ id: '9', title: 'X', summary: '', description: '', externalLink: '', keywords: [] }])

		expect(only.title).toBe('X')
	})

	it('build an Organization per mock entry', () => {
		const organizations = mockOrganizations()

		expect(organizations).toHaveLength(mockOrganizationData().length)
		organizations.forEach((organization) => expect(organization).toBeInstanceOf(Organization))
		expect(organizations.map((organization) => organization.title)).toEqual(mockOrganizationData().map((data) => data.title))
	})
})
