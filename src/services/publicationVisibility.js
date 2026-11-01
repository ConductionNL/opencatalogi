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
