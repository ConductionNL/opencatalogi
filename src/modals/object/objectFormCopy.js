/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * How the object modal seeds its form: a detached copy of the object's
 * editable values, and the catalog a new object starts in. Pure functions.
 */

/**
 * A detached copy of a value, or the value itself when it cannot be
 * serialised.
 *
 * @param {*} value - The value
 * @return {*} The copy
 */
function safeCopy(value) {
	try {
		return JSON.parse(JSON.stringify(value))
	} catch {
		// If JSON serialization fails, use the original value
		return value
	}
}

/**
 * Copies of an object's values, except its metadata and id.
 *
 * @param {object} initial - The object
 * @return {object} The copies
 */
function editableValues(initial) {
	const filtered = {}
	for (const key in initial) {
		if (key !== '@self' && key !== 'id') {
			filtered[key] = safeCopy(initial[key])
		}
	}
	return filtered
}

/**
 * The form copy of the editable values: always a plain object, never an
 * array.
 *
 * @param {object} filtered - The editable values
 * @return {object} The form data
 */
function formObjectOf(filtered) {
	try {
		const parsedData = JSON.parse(JSON.stringify(filtered))
		// Explicitly ensure it's an object
		if (typeof parsedData === 'object' && !Array.isArray(parsedData)) {
			return parsedData
		}
		return { ...filtered }
	} catch {
		// Fallback if JSON serialization fails
		return { ...filtered }
	}
}

/**
 * Turn theme objects in the form back into theme ids.
 *
 * @param {object} formData - The form data, changed in place
 * @return {void}
 */
function themesAsIds(formData) {
	if (formData.themes && Array.isArray(formData.themes)) {
		formData.themes = formData.themes.map((theme) =>
			typeof theme === 'object' ? theme.id : theme,
		)
	}
}

/**
 * The form data an existing object is edited with: detached copies of its
 * editable values, with its themes as ids.
 *
 * @param {object} initial - The object
 * @return {object} The form data
 */
export function editableFormData(initial) {
	const formData = formObjectOf(editableValues(initial))
	themesAsIds(formData)
	return formData
}

/**
 * The catalog a new object starts in: the one of the route's slug, else the
 * only catalog there is.
 *
 * @param {Array<object>} catalogs - The catalogs
 * @param {string|undefined} catalogSlug - The route's catalog slug
 * @return {object|undefined} The catalog
 */
export function initialCatalog(catalogs, catalogSlug) {
	if (catalogSlug) {
		return catalogs.find((catalog) => catalog.slug === catalogSlug)
	}
	return catalogs.length === 1 ? catalogs[0] : undefined
}
