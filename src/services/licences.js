/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The one read of the licence list, shared by every picker on the page. */
let pending = null

/**
 * The EU licences authority list the licence pickers offer (REQ-DCC-001),
 * read once per page from `GET /api/dcat/licences`. A failed read is not
 * kept, so the next picker tries again.
 *
 * @return {Promise<Array<{code: string, iri: string, label: string, labelNl: string}>>} The members.
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export function fetchLicences() {
	if (pending === null) {
		pending = axios
			.get(generateUrl('/apps/opencatalogi/api/dcat/licences'))
			.then((response) => (Array.isArray(response.data?.results) ? response.data.results : []))
			.catch((error) => {
				pending = null
				throw error
			})
	}
	return pending
}

/**
 * Forget the read list, so the next picker reads it again. For tests.
 *
 * @return {void}
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export function forgetLicences() {
	pending = null
}

/**
 * The picker options: one per member, its authority IRI as the stored value,
 * labelled in Dutch for a Dutch reader and in English otherwise, sorted by label.
 *
 * @param {Array<object>} rows The members.
 * @param {string} language The reader's language, such as `nl` or `en`.
 * @return {Array<{id: string, label: string, code: string}>} The options.
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export function licenceOptions(rows, language) {
	const dutch = String(language || '').toLowerCase().startsWith('nl')
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && typeof row.iri === 'string' && row.iri !== '')
		.map((row) => ({
			id: row.iri,
			code: String(row.code || ''),
			label: String((dutch ? row.labelNl : row.label) || row.label || row.code || row.iri),
		}))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The option a stored licence names: by IRI, or by code or label the way the
 * server resolves one (case-insensitive). Null for an empty value or one not on the list.
 *
 * @param {Array<{id: string, label: string, code: string}>} options The options.
 * @param {string|null|undefined} value The stored licence.
 * @return {{id: string, label: string, code: string}|null} The option, or null.
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export function licenceOptionFor(options, value) {
	const wanted = String(value ?? '').trim().toLowerCase()
	if (wanted === '') {
		return null
	}
	return (
		options.find((option) => [option.id, option.code, option.label].some((name) => String(name).toLowerCase() === wanted)) || null
	)
}
