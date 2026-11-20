/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The editor side of a published table's views (dataset-views-on-the-public-page,
 * REQ-DSV-001): which columns a view may use, the record a form saves, the
 * positions a move writes, and what a preview of the data answer says.
 */

/** How a view is drawn. */
export const VIEW_KINDS = ['table', 'bar', 'line', 'map']

/** How a chart combines the rows of one group. */
export const AGGREGATES = ['count', 'sum', 'avg']

/** The dictionary types OpenRegister sums, averages and plots. */
const NUMERIC_TYPES = ['number', 'integer']

/** The fields each kind saves, beside `kind` and `title`. */
const KIND_FIELDS = {
	table: ['columns'],
	bar: ['x', 'y', 'aggregate'],
	line: ['x', 'y', 'aggregate'],
	map: ['latitude', 'longitude', 'label'],
}

/**
 * The names of a table's columns.
 *
 * @param {Array<{name: string}>} dictionary The table's dictionary.
 * @return {Array<string>} The names, in dictionary order.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function columnNames(dictionary) {
	return (dictionary || []).map((column) => String(column.name))
}

/**
 * The names of a table's numeric columns, the only ones a sum, an average or a coordinate may use.
 *
 * @param {Array<{name: string, type?: string}>} dictionary The table's dictionary.
 * @return {Array<string>} The names.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function numericColumns(dictionary) {
	return (dictionary || [])
		.filter((column) => NUMERIC_TYPES.includes(String(column.type || '')))
		.map((column) => String(column.name))
}

/**
 * The form of a view: a saved view's values, or a new table view.
 *
 * @param {object|null} view The saved view, or null for a new one.
 * @return {object} The form.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function viewForm(view) {
	const saved = view || {}
	return {
		kind: VIEW_KINDS.includes(saved.kind) ? saved.kind : 'table',
		title: String(saved.title || ''),
		columns: Array.isArray(saved.columns) ? [...saved.columns] : [],
		x: String(saved.x || ''),
		y: String(saved.y || ''),
		aggregate: AGGREGATES.includes(saved.aggregate) ? saved.aggregate : 'count',
		latitude: String(saved.latitude || ''),
		longitude: String(saved.longitude || ''),
		label: String(saved.label || ''),
	}
}

/**
 * The record a form saves: its kind, title and only the fields of that kind,
 * an empty optional field left out, plus the view id when it changes a saved view.
 *
 * @param {object} form The form.
 * @param {{viewId?: string, position?: number|null}} [saved] The saved view's id and its place on the page.
 * @return {object} The record.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function viewPayload(form, saved = {}) {
	const kind = VIEW_KINDS.includes(form.kind) ? form.kind : 'table'
	const payload = { kind, title: String(form.title || '').trim(), ...filledFields(form, KIND_FIELDS[kind]) }
	if (payload.aggregate === 'count') {
		delete payload.y
	}
	if ((saved.position ?? null) !== null) {
		payload.position = saved.position
	}
	if (saved.viewId) {
		payload.viewId = saved.viewId
	}
	return payload
}

/**
 * The fields of a form that hold a value, an array copied.
 *
 * @param {object} form The form.
 * @param {Array<string>} fields The fields the view's kind uses.
 * @return {object} The filled fields.
 */
function filledFields(form, fields) {
	const filled = {}
	for (const field of fields) {
		const value = form[field]
		if (Array.isArray(value) ? value.length : value) {
			filled[field] = Array.isArray(value) ? [...value] : value
		}
	}
	return filled
}

/**
 * What a form still lacks before it can be saved, as a code, or null when it is complete.
 *
 * @param {object} form The form.
 * @return {string|null} `title`, `columns`, `x`, `y` or `coordinates`.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function missingField(form) {
	if (!String(form.title || '').trim()) {
		return 'title'
	}
	if (form.kind === 'table' && !(form.columns || []).length) {
		return 'columns'
	}
	if ((form.kind === 'bar' || form.kind === 'line') && !form.x) {
		return 'x'
	}
	if ((form.kind === 'bar' || form.kind === 'line') && form.aggregate !== 'count' && !form.y) {
		return 'y'
	}
	if (form.kind === 'map' && (!form.latitude || !form.longitude)) {
		return 'coordinates'
	}
	return null
}

/**
 * The views in their new order after moving one up or down, each with the
 * position it gets, or null when the move leaves the list.
 *
 * @param {Array<object>} views The views in page order.
 * @param {{index: number, delta: number}} move The view to move, and -1 for up or 1 for down.
 * @return {Array<{view: object, position: number}>|null} The two views that change place.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function movedViews(views, { index, delta }) {
	const other = index + delta
	if (index < 0 || other < 0 || index >= views.length || other >= views.length) {
		return null
	}
	return [
		{ view: views[index], position: other },
		{ view: views[other], position: index },
	]
}

/**
 * What a view's data answer holds, for the preview under the form.
 *
 * @param {string} kind The view's kind.
 * @param {object} data The data endpoint's answer.
 * @return {{count: number, total: number, truncated: boolean, skipped: number}} The figures.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export function previewFigures(kind, data) {
	const answer = data || {}
	if (kind === 'map') {
		const count = (answer.features || []).length
		return { count, total: count, truncated: answer.truncated === true, skipped: Number(answer.skipped || 0) }
	}
	if (kind === 'bar' || kind === 'line') {
		const count = (answer.points || []).length
		return { count, total: count, truncated: answer.truncated === true, skipped: 0 }
	}
	const count = (answer.results || []).length
	return { count, total: Number(answer.total ?? count), truncated: false, skipped: 0 }
}
