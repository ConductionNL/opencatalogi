/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether search can read each attachment of a publication: the Text column
 * and the "Read again" action of its file list (scanned-attachments-become-searchable,
 * REQ-SOCR-004). The reading job and its states are the backend's; this module
 * only reads them, names them, and asks for a new reading.
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The label of each reading state, in the order of design D4.
 *
 * @return {Record<string, string>} The labels by state
 */
function stateLabels() {
	return {
		typed: t('opencatalogi', 'Typed text'),
		ocr: t('opencatalogi', 'Read by OCR'),
		waiting: t('opencatalogi', 'Reading'),
		none: t('opencatalogi', 'No text found'),
		unavailable: t('opencatalogi', 'No text: OCR is not installed'),
		failed: t('opencatalogi', 'Failed'),
	}
}

/**
 * The Text column's label for one reading, or an empty string when the
 * attachment has not been read (not published yet) or the state is unknown.
 *
 * @param {{state?: string}|undefined|null} reading - The reading state
 * @return {string} The label
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
export function readingLabel(reading) {
	return stateLabels()[reading?.state] ?? ''
}

/**
 * The tooltip of a reading: its reason, shown for a failed reading and for any
 * reading whose label alone does not say why.
 *
 * @param {{state?: string, reason?: string}|undefined|null} reading - The reading state
 * @return {string} The reason, or an empty string
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
export function readingReason(reading) {
	const reason = typeof reading?.reason === 'string' ? reading.reason : ''
	return reason !== '' && reason !== readingLabel(reading) ? reason : ''
}

/**
 * Read a publication's reading states, keyed by file id. A failed read answers
 * no states and no Read again, so the column stays empty rather than wrong.
 *
 * @param {string} publicationId - The publication's uuid
 * @param {typeof fetch} [fetchFn] - The fetch to use
 * @return {Promise<{readings: Record<string, object>, canReadAgain: boolean}>} The states
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
export async function fetchAttachmentReadings(publicationId, fetchFn = (...args) => fetch(...args)) {
	const empty = { readings: {}, canReadAgain: false }
	if (!publicationId) {
		return empty
	}
	try {
		const response = await fetchFn(
			generateUrl('/apps/opencatalogi/api/publications/{id}/attachment-readings', { id: publicationId }),
			{ headers: { Accept: 'application/json' } },
		)
		if (!response.ok) {
			return empty
		}
		const answer = await response.json()
		const readings = {}
		for (const reading of Array.isArray(answer?.readings) ? answer.readings : []) {
			if (reading && reading.fileId !== undefined) {
				readings[String(reading.fileId)] = reading
			}
		}
		return { readings, canReadAgain: answer?.canReadAgain === true }
	} catch {
		return empty
	}
}

/**
 * Ask for a new reading of one attachment.
 *
 * @param {string} publicationId - The publication's uuid
 * @param {string|number} fileId - The Nextcloud file id
 * @param {string} requestToken - The CSRF token
 * @param {typeof fetch} [fetchFn] - The fetch to use
 * @return {Promise<{ok: boolean, message: string}>} Whether it was queued, and the server's message when not
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
export async function requestReadAgain(publicationId, fileId, requestToken, fetchFn = (...args) => fetch(...args)) {
	const failed = t('opencatalogi', 'The attachment could not be queued for reading.')
	try {
		const response = await fetchFn(
			generateUrl('/apps/opencatalogi/api/publications/{id}/attachment-readings/{fileId}/read-again', {
				id: publicationId,
				fileId,
			}),
			{ method: 'POST', headers: { Accept: 'application/json', requesttoken: requestToken } },
		)
		if (response.ok) {
			return { ok: true, message: '' }
		}
		const answer = await response.json().catch(() => ({}))
		return { ok: false, message: typeof answer?.message === 'string' && answer.message !== '' ? answer.message : failed }
	} catch {
		return { ok: false, message: failed }
	}
}
