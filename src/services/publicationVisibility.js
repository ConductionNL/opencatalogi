/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the publication visibility widget asks OpenRegister for: the route of a
 * publication's files, and those files as the documents a withdrawal can pick.
 */

/**
 * The schema segment of a files route: a schema slug string as it is, else
 * the schema object's slug or id.
 *
 * @param {string|{slug?: string, id?: (string|number)}} schema The schema.
 * @return {string|number} The schema segment.
 */
function schemaSegment(schema) {
	return typeof schema === 'string' ? schema : schema.slug || schema.id
}

/**
 * The route parameters of a publication's files endpoint. The widget's own
 * register and schema win over the ones of the object context; both default
 * to `publication`.
 *
 * @param {{register: string, schema: (string|object), ctx: object, id: string}} source The widget props, its object context and the publication id.
 * @return {{register: string, schema: (string|number), id: string}} The route parameters.
 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
 */
export function filesRouteParams(source) {
	const schema = source.schema || source.ctx.schema || 'publication'
	return {
		register: source.register || source.ctx.register || 'publication',
		schema: schemaSegment(schema),
		id: source.id,
	}
}

/**
 * The documents of a files response (an array or `{results}`), each with an id
 * and a label: its title, else its name, else its id.
 *
 * @param {Array<object>|{results: Array<object>}} data The files response body.
 * @return {Array<{id: (string|number), label: string}>} The documents.
 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
 */
export function documentOptions(data) {
	const files = Array.isArray(data) ? data : data.results || []
	return files.map((file) => ({
		id: file.id,
		label: file.title || file.name || String(file.id),
	}))
}

/**
 * Whether a file's own publication window has ended: its `depublished`
 * moment (OpenRegister's file window) is now or past. A file without an end,
 * or with an unreadable one, is not withdrawn.
 *
 * @param {object} file One file of a files response.
 * @param {Date} [now] The moment to compare with.
 * @return {boolean} Whether the file is withdrawn.
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
 */
export function isWithdrawnFile(file, now = new Date()) {
	if (!file || !file.depublished) {
		return false
	}
	const end = new Date(file.depublished)
	return !Number.isNaN(end.getTime()) && end.getTime() <= now.getTime()
}

/**
 * The withdrawn documents of a files response, each with its id, label, the
 * moment it was withdrawn and the reason kept on the file. OpenRegister turns
 * the `withdrawn-reason:<reason>` label into the `withdrawn-reason` field.
 *
 * @param {Array<object>|{results: Array<object>}} data The files response body.
 * @param {Date} [now] The moment to compare with.
 * @return {Array<{id: (string|number), label: string, withdrawnAt: string, reason: string}>} The withdrawn documents.
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
 */
export function withdrawnDocuments(data, now = new Date()) {
	const files = Array.isArray(data) ? data : data.results || []
	return files
		.filter((file) => isWithdrawnFile(file, now))
		.map((file) => ({
			id: file.id,
			label: file.title || file.name || String(file.id),
			withdrawnAt: file.depublished,
			reason: String(file['withdrawn-reason'] || ''),
		}))
}

/**
 * The visibility moves the page offers for a state. Each shows only when its
 * move applies, and none shows to a caller without the publish right: only
 * publishers and administrators publish or withdraw (REQ-OPR-002).
 *
 * @param {string} state The publication's state from the server.
 * @param {boolean} canPublish Whether the caller holds the publish right.
 * @return {{publishNow: boolean, publishAgain: boolean, withdraw: boolean}} Which moves show.
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-screen-offers-publish-actions-only-to-publishers-req-opr-002
 */
export function visibilityActions(state, canPublish) {
	const allowed = canPublish === true
	return {
		publishNow: allowed && (state === 'draft' || state === 'approved' || state === 'scheduled'),
		publishAgain: allowed && state === 'withdrawn',
		withdraw: allowed && (state === 'public' || state === 'scheduled'),
	}
}

/**
 * The words for a refused move: the server's own sentence when it refused a
 * caller outside the publishers group (it names the group), else none.
 *
 * @param {object} error The axios error.
 * @return {string} The server's message, or an empty string.
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
 */
export function publishRefusalMessage(error) {
	const data = error && error.response ? error.response.data : null
	if (data && data.error === 'not-a-publisher' && typeof data.message === 'string') {
		return data.message
	}
	return ''
}

/**
 * Whether the publish route refused because the publication carries no
 * documents and the call did not confirm it (409 `no-documents`, REQ-PSG-004).
 * The page then asks a second time instead of reporting a conflict.
 *
 * @param {object} error The axios error.
 * @return {boolean} True for that refusal.
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */
export function isNoDocumentsRefusal(error) {
	const response = error && error.response ? error.response : null
	return Boolean(response && response.status === 409 && response.data && response.data.error === 'no-documents')
}

/**
 * The publication's automatic depublication moment when it lies ahead: its
 * `depublicationDate` when that is a valid date after `now`, else null
 * (REQ-PSG-003). A past or unreadable date says nothing.
 *
 * @param {object|null} publication The publication's properties.
 * @param {Date} [now] The moment to compare with.
 * @return {Date|null} The coming depublication, or null.
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.1
 */
export function comingDepublication(publication, now = new Date()) {
	const value = publication ? publication.depublicationDate : null
	if (!value) {
		return null
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime()) || date.getTime() <= now.getTime()) {
		return null
	}
	return date
}

/**
 * The list moves the page offers: Unlist on a public or scheduled publication
 * that is listed, List again on an unlisted one, both only to a caller with
 * the update right (publication-lifecycle-on-or REQ-PLC-006).
 *
 * @param {string} state The publication's state from the server.
 * @param {boolean} unlisted Whether the publication is unlisted.
 * @param {boolean} canUpdate Whether the caller may update it.
 * @return {{unlist: boolean, listAgain: boolean}} Which moves show.
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
 */
export function listActions(state, unlisted, canUpdate) {
	const allowed = canUpdate === true
	return {
		unlist: allowed && unlisted !== true && (state === 'public' || state === 'scheduled'),
		listAgain: allowed && unlisted === true,
	}
}

/**
 * Whether the page offers Delete permanently: only for a draft or a
 * publication in review, and only to a caller with the update right. The
 * server still proves it was never published before it deletes anything
 * (publication-lifecycle-on-or-draft-purge REQ-PLC-004).
 *
 * @param {string} state The publication's state from the server.
 * @param {boolean} canUpdate Whether the caller may update it.
 * @return {boolean} Whether Delete permanently shows.
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */
export function canDeletePermanently(state, canUpdate) {
	return canUpdate === true && (state === 'draft' || state === 'in_review')
}

/**
 * The words for a refused or failed permanent delete: the server's own
 * sentence, with the removed items named when it stopped part way, else none.
 *
 * @param {object} error The axios error.
 * @return {string} The message, or an empty string when the server gave none.
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */
export function draftPurgeMessage(error) {
	const data = error && error.response ? error.response.data : null
	if (!data || typeof data.message !== 'string') {
		return ''
	}
	const removed = Array.isArray(data.removed) ? data.removed : []
	if (data.error !== 'purge-incomplete' || removed.length === 0) {
		return data.message
	}
	const names = removed.map((item) => (item && (item.title || item.name || item.id)) || String(item))
	return data.message + ' ' + names.join(', ')
}
