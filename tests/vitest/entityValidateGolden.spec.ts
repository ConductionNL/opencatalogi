/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the entities' validate(): whether each mock, an empty
 * entity and a deliberately wrong one pass, and every issue zod reports.
 * Each entity is validated twice in a row, because some schemas use global
 * regexes. Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { mockAttachmentsData } from '../../src/entities/attachment/attachment.mock.ts'
import { Attachment } from '../../src/entities/attachment/attachment.ts'
import { mockCatalogiList } from '../../src/entities/catalogi/catalogi.mock.ts'
import { Catalogi } from '../../src/entities/catalogi/catalogi.ts'
import { mockConfigurationData } from '../../src/entities/configuration/configuration.mock.ts'
import { Configuration } from '../../src/entities/configuration/configuration.ts'
import { mockOrganizationData } from '../../src/entities/organization/organization.mock.ts'
import { Organization } from '../../src/entities/organization/organization.ts'
import { mockPublicationsData } from '../../src/entities/publication/publication.mock.ts'
import { Publication } from '../../src/entities/publication/publication.ts'
import { mockPublicationTypeData } from '../../src/entities/publicationType/publicationType.mock.ts'
import { PublicationType } from '../../src/entities/publicationType/publicationType.ts'
import { mockThemeData } from '../../src/entities/theme/theme.mock.ts'
import { Theme } from '../../src/entities/theme/theme.ts'

const FIXTURE = path.resolve(__dirname, 'fixtures/entity-validate-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'

type Validatable = { validate: () => { success: boolean; error?: unknown } }
type Build = (data: never) => Validatable

const WRONG = {
	id: 5,
	title: 7,
	summary: 'x'.repeat(300),
	description: 'x'.repeat(3000),
	image: 'not a url',
	url: 'not a url',
	status: 'Unknown',
	language: { code: 'xx_yy', level: 'Z9' },
	anonymization: { anonymized: 'maybe', results: 'x'.repeat(3000) },
	published: 'yesterday',
	modified: 'today',
	email: 'nope',
	rsin: 'abc',
	register: '',
	schema: '',
	properties: 'none',
	archive: { date: 'later' },
}

const NEAR_MISS = {
	...WRONG,
	language: { code: 'nld-bel', level: 'C3' },
}

const ENTITIES: Record<string, { build: Build; mocks: unknown[] }> = {
	attachment: {
		build: (d) => new Attachment(d),
		mocks: mockAttachmentsData(),
	},
	catalogi: { build: (d) => new Catalogi(d), mocks: mockCatalogiList },
	configuration: {
		build: (d) => new Configuration(d),
		mocks: mockConfigurationData(),
	},
	organization: {
		build: (d) => new Organization(d),
		mocks: mockOrganizationData(),
	},
	publication: {
		build: (d) => new Publication(d),
		mocks: mockPublicationsData(),
	},
	publicationType: {
		build: (d) => new PublicationType(d),
		mocks: mockPublicationTypeData(),
	},
	theme: { build: (d) => new Theme(d), mocks: mockThemeData() },
}

/**
 * The outcome of one validate() call.
 *
 * @param result The zod result
 * @return Whether it passed and the issues
 */
function outcome(result: { success: boolean; error?: unknown }): unknown {
	const issues =
		(result.error as { issues?: Array<Record<string, unknown>> })?.issues || []
	return {
		success: result.success,
		issues: issues.map(({ path: at, code, message }) => ({
			path: at,
			code,
			message,
		})),
	}
}

/**
 * Build an entity and validate it twice.
 *
 * @param build The constructor call
 * @param data The raw data
 * @return Both outcomes, or what the constructor threw
 */
function validateTwice(build: Build, data: unknown): unknown {
	try {
		const entity = build(data as never)
		return [outcome(entity.validate()), outcome(entity.validate())]
	} catch (error) {
		return { thrown: (error as Error).constructor.name }
	}
}

describe('entity validate (golden)', () => {
	it('matches the recorded outcomes', () => {
		const traces: Record<string, unknown> = {}
		for (const [name, { build, mocks }] of Object.entries(ENTITIES)) {
			mocks.forEach((data, i) => {
				traces[`${name} mock ${i}`] = validateTwice(build, data)
			})
			traces[`${name} empty`] = validateTwice(build, {})
			traces[`${name} wrong`] = validateTwice(build, WRONG)
			traces[`${name} near miss`] = validateTwice(build, NEAR_MISS)
		}
		const actual = JSON.parse(JSON.stringify(traces))
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
