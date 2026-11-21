/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { languageName, publicationLanguagesFrom } from '../../services/publicationTranslations.js'

export { languageName, publicationLanguagesFrom }

/**
 * The requests of the admin settings page and the mapping of their answers:
 * the publishing options, the federation sync interval and the manual
 * directory sync.
 */

/**
 * The publishing options of a backend answer.
 *
 * @param {object} data - The answer
 * @return {{autoPublishAttachments: boolean, autoPublishObjects: boolean, useOldStylePublishingView: boolean}} The options
 */
export function publishingOptionsFrom(data) {
	return {
		autoPublishAttachments: data.auto_publish_attachments,
		autoPublishObjects: data.auto_publish_objects,
		useOldStylePublishingView: data.use_old_style_publishing_view,
		// Publication-lifecycle-on-or REQ-PLC-001: on unless the server says otherwise.
		publicationReviewRequired: data.publication_review_required !== false,
	}
}

/**
 * The backend payload of the publishing options.
 *
 * @param {object} options - The publishing options
 * @return {object} The payload
 */
export function publishingOptionsPayload(options) {
	return {
		auto_publish_attachments: options.autoPublishAttachments,
		auto_publish_objects: options.autoPublishObjects,
		use_old_style_publishing_view: options.useOldStylePublishingView,
		publication_review_required: options.publicationReviewRequired,
	}
}

/**
 * The sync interval to store, in seconds: the typed minutes, else the
 * default minutes.
 *
 * @param {{intervalMinutes: string|number, defaultMinutes: number}} syncOptions - The sync options
 * @return {number} The interval in seconds
 */
export function syncIntervalSeconds(syncOptions) {
	const minutes = Number.parseInt(syncOptions.intervalMinutes, 10)
	return Number.isFinite(minutes)
		? minutes * 60
		: syncOptions.defaultMinutes * 60
}

/**
 * The request options of a JSON POST to an OCS-aware endpoint.
 *
 * @return {{method: string, headers: object}} The request options
 */
function ocsJsonPost() {
	return {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'OCS-APIRequest': 'true',
		},
	}
}

/**
 * POST a sync interval to the backend.
 *
 * @param {number} seconds - The interval in seconds
 * @return {Promise<object>} The parsed answer
 */
export async function postSyncInterval(seconds) {
	const response = await fetch(
		'/index.php/apps/opencatalogi/api/settings/sync',
		{
			...ocsJsonPost(),
			body: JSON.stringify({ sync_interval_seconds: seconds }),
		},
	)
	return response.json()
}

/**
 * Ask the backend to synchronize all known federation directories.
 *
 * @return {Promise<{response: Response, data: object}>} The response and its parsed body
 */
export async function requestDirectorySync() {
	const response = await fetch(
		'/index.php/apps/opencatalogi/api/listings/sync',
		ocsJsonPost(),
	)
	const data = await response.json()
	return { response, data }
}

/**
 * Whether a directory sync answer reports a failure.
 *
 * @param {Response} response - The response
 * @param {object} data - The parsed body
 * @return {boolean} True when it failed
 */
export function directorySyncFailed(response, data) {
	return !response.ok || Boolean(data.error)
}

/**
 * The detail of a failed directory sync: the answer's message, its error,
 * else the status text.
 *
 * @param {Response} response - The response
 * @param {object} data - The parsed body
 * @return {string} The detail
 */
export function directorySyncFailure(response, data) {
	return data.message || data.error || response.statusText
}

/**
 * The counts of a directory sync answer; the total defaults to the synced
 * plus the failed directories.
 *
 * @param {object} data - The parsed body
 * @return {{synced: number, total: number, failed: number}} The counts
 */
export function directorySyncCounts(data) {
	const synced = data.synced_directories ?? 0
	const failed = data.failed_directories ?? 0
	const total = data.total_directories ?? synced + failed
	return { synced, total, failed }
}

/**
 * The languages an administrator can offer to editors, as BCP 47 tags.
 * Dutch is the source language of every publication and always offered.
 */
export const PUBLICATION_LANGUAGE_TAGS = ['nl', 'en', 'de', 'fr', 'fy', 'es', 'it', 'pl', 'pt', 'tr', 'ar', 'uk']

/**
 * The select options of the publication languages: every offerable language
 * and any stored one outside that list, Dutch locked as the source.
 *
 * @param {Array<string>} selected - The selected tags
 * @param {string} locale - The reader's locale
 * @return {Array<{id: string, label: string, locked: boolean}>} The options
 * @spec openspec/changes/publication-translations/design.md
 */
export function publicationLanguageOptions(selected, locale) {
	const tags = [...PUBLICATION_LANGUAGE_TAGS]
	for (const tag of selected) {
		if (!tags.includes(tag)) {
			tags.push(tag)
		}
	}
	return tags.map((tag) => ({ id: tag, label: languageName(tag, locale), locked: tag === 'nl' }))
}

/**
 * The publication languages to save from the selected options, Dutch first.
 *
 * @param {Array<{id: string}>} options - The selected options
 * @return {Array<string>} The tags
 * @spec openspec/changes/publication-translations/design.md
 */
export function publicationLanguagesPayload(options) {
	return publicationLanguagesFrom((options || []).map((option) => option.id))
}

/**
 * The stored CORS allowlist as the lines the settings field shows: one website per line.
 *
 * @param {string|undefined} stored - The `cors_allowed_origins` value, comma separated
 * @return {string} The lines; `*` when nothing is stored
 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
 */
export function corsOriginLines(stored) {
	if (typeof stored !== 'string' || stored.trim() === '') {
		return '*'
	}

	return stored.split(',').map((origin) => origin.trim()).filter((origin) => origin !== '').join('\n')
}

/**
 * The reason a CORS allowlist save was refused, as the server named it.
 *
 * @param {object} error - The rejected request
 * @return {string} The server's reason, or '' when it gave none
 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
 */
export function corsSaveRefusal(error) {
	const answer = error && error.response
	if (!answer || answer.status !== 400 || !answer.data || typeof answer.data.error !== 'string') {
		return ''
	}

	return answer.data.error
}

/**
 * The stored embargo lift groups as the text the settings field shows: group ids separated by commas.
 *
 * @param {string|undefined} stored - The `publication_embargo_lift_groups` value, a JSON list
 * @return {string} The group ids; `admin`, the server's default, when nothing readable is stored
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
 */
export function liftGroupsText(stored) {
	let groups
	try {
		groups = JSON.parse(stored)
	} catch {
		groups = []
	}

	const ids = (Array.isArray(groups) ? groups : [])
		.filter((group) => typeof group === 'string' && group.trim() !== '')
		.map((group) => group.trim())

	return ids.length > 0 ? ids.join(', ') : 'admin'
}

/**
 * The embargo lift groups to save from the field's text: each group id trimmed and once.
 *
 * @param {string} text - The field's text, group ids separated by commas
 * @return {Array<string>} The group ids
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
 */
export function liftGroupsPayload(text) {
	return [
		...new Set(
			String(text || '')
				.split(',')
				.map((group) => group.trim())
				.filter((group) => group !== ''),
		),
	]
}
