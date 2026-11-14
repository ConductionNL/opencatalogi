/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publication and catalogue translations (publication-translations,
 * REQ-PTR-003/004). The storage, the fallback and the completeness are
 * OpenRegister's; this module only shapes what the edit pages and the
 * Languages card send and show.
 */

/**
 * The language every publication and catalogue is written in.
 */
export const SOURCE_LANGUAGE = 'nl'

/**
 * The fields of a publication and a catalogue that carry a translation.
 */
export const TRANSLATABLE_FIELDS = ['title', 'summary', 'description']

/**
 * The stored publication languages: the JSON list the settings carry, Dutch
 * first; Dutch and English when nothing usable is stored.
 *
 * @param {string|Array<string>|undefined} stored - The stored value
 * @return {Array<string>} The language tags
 * @spec openspec/changes/publication-translations/design.md
 */
export function publicationLanguagesFrom(stored) {
	let tags = stored
	if (typeof stored === 'string') {
		try {
			tags = JSON.parse(stored)
		} catch {
			tags = null
		}
	}
	if (!Array.isArray(tags) || tags.length === 0) {
		return [SOURCE_LANGUAGE, 'en']
	}
	const languages = [SOURCE_LANGUAGE]
	for (const tag of tags) {
		if (typeof tag === 'string' && tag !== '' && !languages.includes(tag)) {
			languages.push(tag)
		}
	}
	return languages
}

/**
 * The name of a language in the reader's own language, else its tag.
 *
 * @param {string} tag - The BCP 47 tag
 * @param {string} locale - The reader's locale
 * @return {string} The name
 * @spec openspec/changes/publication-translations/design.md
 */
export function languageName(tag, locale) {
	try {
		return new Intl.DisplayNames([locale], { type: 'language' }).of(tag) || tag
	} catch {
		return tag
	}
}

/**
 * The schema the edit form shows in another language than Dutch: every
 * field that is not translatable becomes read-only, since it is the same
 * in every language.
 *
 * @param {object|null} schema - The schema
 * @param {string} language - The language being edited
 * @return {object|null} The schema to show
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function translationSchema(schema, language) {
	if (!schema || language === SOURCE_LANGUAGE) {
		return schema
	}
	const properties = {}
	for (const [key, property] of Object.entries(schema.properties || {})) {
		properties[key] = TRANSLATABLE_FIELDS.includes(key)
			? property
			: { ...property, readOnly: true }
	}
	return { ...schema, properties }
}

/**
 * The value of one field in one language: a language-keyed value's entry,
 * a plain value for Dutch only, else ''.
 *
 * @param {string|object|null} value - The field value, plain or keyed by language
 * @param {string} language - The language
 * @return {string} The value in that language
 */
function valueIn(value, language) {
	if (value && typeof value === 'object' && !Array.isArray(value)) {
		const entry = value[language]
		return typeof entry === 'string' ? entry : ''
	}
	if (language === SOURCE_LANGUAGE && typeof value === 'string') {
		return value
	}
	return ''
}

/**
 * The object the edit form shows in one language: the translatable fields
 * in that language (empty when not translated yet), the rest as they are.
 *
 * @param {object|null} object - The object as shown in Dutch
 * @param {object|null} allTranslations - The object read with every translation
 * @param {string} language - The language being edited
 * @return {object|null} The object to show
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function translationItem(object, allTranslations, language) {
	if (!object || language === SOURCE_LANGUAGE) {
		return object
	}
	const item = { ...object }
	for (const field of TRANSLATABLE_FIELDS) {
		item[field] = valueIn((allTranslations || {})[field], language)
	}
	return item
}

/**
 * What a save in another language writes: only the translatable fields the
 * editor changed.
 *
 * @param {object} formData - The changed fields
 * @return {object} The fields to write
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function translationPayload(formData) {
	const payload = {}
	for (const field of TRANSLATABLE_FIELDS) {
		if (formData && Object.hasOwn(formData, field)) {
			payload[field] = formData[field]
		}
	}
	return payload
}

/**
 * The request options of a save in one language: a PATCH that writes only
 * the given fields, for that language, through OpenRegister's
 * `X-Translation-Target-Language`.
 *
 * @param {object} payload - The fields to write
 * @param {string} language - The language written
 * @param {string} requestToken - The CSRF token
 * @return {{method: string, headers: object, body: string}} The request options
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function translationSaveOptions(payload, language, requestToken) {
	return {
		method: 'PATCH',
		headers: {
			'Content-Type': 'application/json',
			'X-Translation-Target-Language': language,
			requesttoken: requestToken,
		},
		body: JSON.stringify(payload),
	}
}

/**
 * The translation rows of one language from OpenRegister's per-object answer.
 *
 * @param {object|null} answer - The answer of GET /api/translations/object/{uuid}
 * @param {string} language - The language
 * @return {Array<object>} The rows
 */
function rowsIn(answer, language) {
	const rows =
		answer && Array.isArray(answer.translations) ? answer.translations : []
	return rows.filter(
		(row) =>
			row
			&& row.language === language
			&& TRANSLATABLE_FIELDS.includes(row.property),
	)
}

/**
 * The translatable fields whose translation in one language is outdated:
 * the Dutch text changed since it was translated.
 *
 * @param {object|null} answer - The answer of GET /api/translations/object/{uuid}
 * @param {string} language - The language
 * @return {Array<string>} The field names, in field order
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function outdatedFields(answer, language) {
	const outdated = rowsIn(answer, language)
		.filter((row) => row.status === 'outdated')
		.map((row) => row.property)
	return TRANSLATABLE_FIELDS.filter((field) => outdated.includes(field))
}

/**
 * The state of every enabled language of one object: the source, or which
 * translatable fields are translated, missing and outdated.
 *
 * @param {object|null} answer - The answer of GET /api/translations/object/{uuid}
 * @param {Array<string>} languages - The enabled languages
 * @return {Array<{language: string, source: boolean, translated: Array<string>, missing: Array<string>, outdated: Array<string>}>} One state per language
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
 */
export function languageStates(answer, languages) {
	return languages.map((language) => {
		if (language === SOURCE_LANGUAGE) {
			return {
				language,
				source: true,
				translated: [...TRANSLATABLE_FIELDS],
				missing: [],
				outdated: [],
			}
		}
		const filled = rowsIn(answer, language)
			.filter(
				(row) => typeof row.value === 'string' && row.value.trim() !== '',
			)
			.map((row) => row.property)
		const outdated = outdatedFields(answer, language)
		return {
			language,
			source: false,
			translated: TRANSLATABLE_FIELDS.filter(
				(field) => filled.includes(field) && !outdated.includes(field),
			),
			missing: TRANSLATABLE_FIELDS.filter((field) => !filled.includes(field)),
			outdated,
		}
	})
}

/**
 * Field names as one phrase, the first capitalised when asked:
 * "Title", "Title and summary", "Title, summary and description".
 *
 * @param {Array<string>} fields - The field names
 * @param {(app: string, text: string, vars?: object) => string} t - The translate function
 * @param {boolean} capitalise - Whether the phrase starts a sentence
 * @return {string} The phrase
 */
function fieldPhrase(fields, t, capitalise) {
	const names = fields.map((field) => fieldName(field, t))
	if (capitalise && names.length > 0) {
		names[0] = names[0].charAt(0).toUpperCase() + names[0].slice(1)
	}
	if (names.length < 2) {
		return names.join('')
	}
	return t('opencatalogi', '{first} and {last}', {
		first: names.slice(0, -1).join(', '),
		last: names[names.length - 1],
	})
}

/**
 * The lower-case name of a translatable field.
 *
 * @param {string} field - The field
 * @param {(app: string, text: string, vars?: object) => string} t - The translate function
 * @return {string} The name
 */
function fieldName(field, t) {
	const names = {
		title: t('opencatalogi', 'Title').toLocaleLowerCase(),
		summary: t('opencatalogi', 'Summary').toLocaleLowerCase(),
		description: t('opencatalogi', 'Description').toLocaleLowerCase(),
	}
	return names[field] || field
}

/**
 * The line the Languages card shows for one language.
 *
 * @param {{source: boolean, translated: Array<string>, missing: Array<string>, outdated: Array<string>}} state - The language's state
 * @param {(app: string, text: string, vars?: object) => string} t - The translate function
 * @return {string} The line
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
 */
export function languageSummary(state, t) {
	if (state.source) {
		return t('opencatalogi', 'Source language, complete')
	}
	const parts = []
	if (state.missing.length === 0 && state.outdated.length === 0) {
		parts.push(t('opencatalogi', 'Complete'))
	} else if (state.translated.length === 0 && state.outdated.length === 0) {
		parts.push(t('opencatalogi', 'Not translated yet'))
	} else if (state.translated.length > 0 && state.missing.length > 0) {
		parts.push(
			t('opencatalogi', '{translated} translated, {missing} missing', {
				translated: fieldPhrase(state.translated, t, true),
				missing: fieldPhrase(state.missing, t, false),
			}),
		)
	} else if (state.translated.length > 0) {
		parts.push(
			t('opencatalogi', '{translated} translated', {
				translated: fieldPhrase(state.translated, t, true),
			}),
		)
	} else if (state.missing.length > 0) {
		parts.push(
			t('opencatalogi', '{missing} missing', {
				missing: fieldPhrase(state.missing, t, true),
			}),
		)
	}
	if (state.outdated.length > 0) {
		parts.push(
			t('opencatalogi', 'Outdated: {fields}', {
				fields: fieldPhrase(state.outdated, t, false),
			}),
		)
	}
	return parts.join('. ')
}

/**
 * The language the next edit page opens in, asked by "Translate in the
 * editor" on the Languages card; taken once.
 */
let requestedEditorLanguage = null

/**
 * Ask the next edit page to open in a language.
 *
 * @param {string} language - The language
 * @return {void}
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
 */
export function requestEditorLanguage(language) {
	requestedEditorLanguage = language
}

/**
 * The language the edit page was asked to open in, else Dutch; the request
 * is cleared, so the next edit opens in Dutch again.
 *
 * @return {string} The language
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
 */
export function takeRequestedEditorLanguage() {
	const language = requestedEditorLanguage || SOURCE_LANGUAGE
	requestedEditorLanguage = null
	return language
}

/**
 * Whether a schema declares a translatable field.
 *
 * @param {object|null} schema - The schema
 * @return {boolean} True when one of its properties is translatable
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export function hasTranslatableFields(schema) {
	return Object.values((schema && schema.properties) || {}).some(
		(property) => property && property.translatable === true,
	)
}
