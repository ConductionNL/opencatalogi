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
