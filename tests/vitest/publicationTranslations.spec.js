/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for src/services/publicationTranslations.js: what the edit pages send
 * when an editor writes another language, and what the Languages card says
 * per language (publication-translations REQ-PTR-003/004). Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	languageStates,
	languageSummary,
	outdatedFields,
	publicationLanguagesFrom,
	translationItem,
	translationPayload,
	translationSaveOptions,
	translationSchema,
} from '../../src/services/publicationTranslations.js'

/**
 * A translate function that fills in the placeholders, as @nextcloud/l10n does.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, name) =>
		name in vars ? vars[name] : match,
	)
}

/**
 * A translation row as OpenRegister serialises it.
 *
 * @param {string} property The property.
 * @param {string} language The language.
 * @param {string} value The value.
 * @param {string} status The workflow status.
 * @return {object} The row.
 */
function row(property, language, value, status = 'draft') {
	return {
		objectUuid: 'u1',
		property,
		language,
		value,
		status,
		isSource: language === 'nl',
	}
}

describe('publicationLanguagesFrom', () => {
	it('keeps Dutch first and once, and falls back to Dutch and English', () => {
		expect(publicationLanguagesFrom('["en","nl","de"]')).toEqual([
			'nl',
			'en',
			'de',
		])
		expect(publicationLanguagesFrom(['fr', 7, 'fr'])).toEqual(['nl', 'fr'])
		expect(publicationLanguagesFrom('{')).toEqual(['nl', 'en'])
		expect(publicationLanguagesFrom(null)).toEqual(['nl', 'en'])
	})
})

describe('the edit form in another language (REQ-PTR-003)', () => {
	const schema = {
		title: 'Publication',
		properties: {
			title: { type: 'string' },
			summary: { type: 'string' },
			description: { type: 'string' },
			organization: { type: 'string' },
			publicationDate: { type: 'string', format: 'date' },
		},
	}

	it('makes every field that is not translatable read-only', () => {
		const shown = translationSchema(schema, 'en')

		expect(shown.properties.title).toEqual({ type: 'string' })
		expect(shown.properties.description).toEqual({ type: 'string' })
		expect(shown.properties.organization).toEqual({
			type: 'string',
			readOnly: true,
		})
		expect(shown.properties.publicationDate).toEqual({
			type: 'string',
			format: 'date',
			readOnly: true,
		})
		expect(schema.properties.organization.readOnly).toBeUndefined()
	})

	it('leaves the Dutch form as it is', () => {
		expect(translationSchema(schema, 'nl')).toBe(schema)
		expect(translationItem({ title: 'Jaarverslag' }, null, 'nl')).toEqual({
			title: 'Jaarverslag',
		})
	})

	it('shows the fields in the language edited, empty where not translated yet', () => {
		const object = {
			id: 'u1',
			title: 'Jaarverslag',
			summary: 'Kort',
			description: 'Lang',
			organization: 'org-1',
		}
		const all = {
			title: { nl: 'Jaarverslag', en: 'Annual report' },
			summary: { nl: 'Kort' },
			description: 'Lang',
		}

		expect(translationItem(object, all, 'en')).toEqual({
			id: 'u1',
			title: 'Annual report',
			summary: '',
			description: '',
			organization: 'org-1',
		})
	})

	it('writes only the changed translatable fields, with a PATCH for that language', () => {
		const payload = translationPayload({
			summary: 'Short',
			organization: 'org-2',
			title: '',
		})

		expect(payload).toEqual({ summary: 'Short', title: '' })
		expect(translationSaveOptions(payload, 'en', 'tok')).toEqual({
			method: 'PATCH',
			headers: {
				'Content-Type': 'application/json',
				'X-Translation-Target-Language': 'en',
				requesttoken: 'tok',
			},
			body: '{"title":"","summary":"Short"}',
		})
	})

	it('names the fields whose Dutch changed since they were translated', () => {
		const answer = {
			translations: [
				row('summary', 'en', 'Short', 'outdated'),
				row('title', 'en', 'T', 'outdated'),
				row('title', 'de', 'T', 'outdated'),
			],
		}

		expect(outdatedFields(answer, 'en')).toEqual(['title', 'summary'])
		expect(outdatedFields(null, 'en')).toEqual([])
	})
})

describe('the Languages card (REQ-PTR-004)', () => {
	it('reads "Title and summary translated, description missing" for a partial English translation', () => {
		const answer = {
			translations: [
				row('title', 'en', 'Annual report'),
				row('summary', 'en', 'Short'),
				row('description', 'en', '  '),
			],
		}

		const [dutch, english] = languageStates(answer, ['nl', 'en'])

		expect(dutch).toEqual({
			language: 'nl',
			source: true,
			translated: ['title', 'summary', 'description'],
			missing: [],
			outdated: [],
		})
		expect(english).toEqual({
			language: 'en',
			source: false,
			translated: ['title', 'summary'],
			missing: ['description'],
			outdated: [],
		})
		expect(languageSummary(dutch, t)).toBe('Source language, complete')
		expect(languageSummary(english, t)).toBe(
			'Title and summary translated, description missing',
		)
	})

	it('says complete, not translated, missing and outdated', () => {
		const answer = {
			translations: [
				row('title', 'en', 'A'),
				row('summary', 'en', 'B'),
				row('description', 'en', 'C'),
				row('title', 'fr', 'A'),
				row('summary', 'fr', 'B', 'outdated'),
				row('description', 'fr', 'C'),
				row('summary', 'it', 'B', 'outdated'),
			],
		}
		const lines = languageStates(answer, ['en', 'fr', 'de', 'it']).map((state) =>
			languageSummary(state, t),
		)

		expect(lines).toEqual([
			'Complete',
			'Title and description translated. Outdated: summary',
			'Not translated yet',
			'Title and description missing. Outdated: summary',
		])
	})

	it('lists three fields with commas', () => {
		expect(
			languageSummary(
				{
					source: false,
					translated: [],
					missing: ['title', 'summary', 'description'],
					outdated: ['title'],
				},
				t,
			),
		).toBe('Title, summary and description missing. Outdated: title')
		expect(
			languageSummary(
				{
					source: false,
					translated: ['summary'],
					missing: [],
					outdated: [],
				},
				t,
			),
		).toBe('Complete')
		expect(
			languageSummary(
				{
					source: false,
					translated: ['summary', 'title'],
					missing: [],
					outdated: ['description'],
				},
				t,
			),
		).toBe('Summary and title translated. Outdated: description')
	})
})
