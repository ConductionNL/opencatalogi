/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The values the catalog modal starts from when it edits an existing catalog.
 */

/**
 * The status option of a catalog status, compared case-insensitively, else
 * the first option.
 *
 * @param {string|undefined} status The catalog status.
 * @param {Array<{id: string, label: string}>} statusOptions The status options.
 * @return {{id: string, label: string}} The option.
 */
function statusOption(status, statusOptions) {
	const id = (status || '').toLowerCase()
	return statusOptions.find((opt) => opt.id === id) || statusOptions[0]
}

/**
 * The form values of a catalog: the catalog itself, with its id taken from
 * `@self` when it has none at the top level, its filters as an object (a
 * filters array becomes empty), and its status as a status option.
 *
 * @param {object} catalog The catalog being edited.
 * @param {Array<{id: string, label: string}>} statusOptions The status options.
 * @return {object} The form values.
 * @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1
 */
export function catalogFormFromObject(catalog, statusOptions) {
	return {
		...catalog,
		id: catalog.id || catalog['@self']?.id || '',
		filters: Array.isArray(catalog.filters) ? {} : catalog.filters || {},
		status: statusOption(catalog.status, statusOptions),
	}
}

/**
 * The organization option of a catalog: the organization whose id matches
 * the catalog's organization, compared as strings.
 *
 * @param {object} catalog The catalog being edited.
 * @param {Array<object>} organizations The organizations.
 * @return {{id: (string|number), label: string}|null} The option, or null when none matches.
 * @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1
 */
export function catalogOrganizationOption(catalog, organizations) {
	const wanted = catalog.organization
	const org = organizations.find(
		(o) => o.id && wanted && o.id.toString() === wanted.toString(),
	)
	return org ? { id: org.id, label: org.title } : null
}

/**
 * Select options for a list of ids, labelled with the title of the matching
 * available item, else the id.
 *
 * @param {Array<string|number>} ids The ids.
 * @param {Array<object>} available The available items.
 * @return {Array<{id: (string|number), label: (string|number)}>} The options.
 * @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1
 */
export function idOptions(ids, available) {
	return ids.map((id) => ({
		id,
		label: available.find((item) => item.id === id)?.title || id,
	}))
}
