/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the publication tables widget works out from the server's answers: which
 * attachments can still be published as a table, and which dictionary edits
 * to send.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */

/**
 * Whether a file is a CSV, by its name or its media type.
 *
 * @param {{name?: string, title?: string, type?: string, mimetype?: string}} file The file.
 * @return {boolean} True for a CSV.
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
export function isCsv(file) {
	const name = String(file.name || file.title || '').toLowerCase()
	const type = String(file.type || file.mimetype || '').toLowerCase()
	return name.endsWith('.csv') || type === 'text/csv'
}

/**
 * The CSV attachments of a files response that are not a table yet.
 *
 * @param {Array<object>|{results: Array<object>}} data The files response body.
 * @param {Array<{file: (string|number)}>} tables The publication's tables.
 * @return {Array<{id: string, label: string}>} The attachments to offer.
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
export function csvAttachments(data, tables) {
	const files = Array.isArray(data) ? data : (data && data.results) || []
	const published = new Set(tables.map((table) => String(table.file)))
	return files
		.filter((file) => isCsv(file) && !published.has(String(file.id)))
		.map((file) => ({ id: String(file.id), label: file.title || file.name || String(file.id) }))
}

/**
 * The columns whose title or description the editor changed, as the dictionary endpoint takes them.
 *
 * @param {Array<{name: string, title: string, description: string}>} original The dictionary as loaded.
 * @param {Array<{name: string, title: string, description: string}>} edited The dictionary as edited.
 * @return {Array<{name: string, title: string, description: string}>} The changed columns.
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
 */
export function dictionaryChanges(original, edited) {
	const before = new Map(original.map((column) => [column.name, column]))
	return edited
		.filter((column) => {
			const was = before.get(column.name)
			return was && (was.title !== column.title || was.description !== column.description)
		})
		.map(({ name, title, description }) => ({ name, title, description }))
}
