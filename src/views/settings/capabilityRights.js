/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rights table in the admin settings (woo-access-boundary-hardening,
 * REQ-WAB-004): the eight rights, the groups granted each, and what is sent
 * back when an administrator changes a grant.
 */

/**
 * The words for each right, in the order the server declares them.
 *
 * @param {function(string, string, object=): string} t The translate function.
 * @return {Object<string, string>} The label per verb.
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
export function rightLabels(t) {
	return {
		openInspection: t('opencatalogi', 'Open an inspection period'),
		openCommentPeriod: t('opencatalogi', 'Open a comment period'),
		depublish: t('opencatalogi', 'Depublish and acknowledge a withdrawal'),
		announceNationally: t('opencatalogi', 'Announce to the national index'),
		decideRetention: t('opencatalogi', 'Decide on retention'),
		processWooBatch: t('opencatalogi', 'Process a Woo batch'),
		publishWooBatch: t('opencatalogi', 'Publish a Woo batch'),
		requestWooRegistration: t('opencatalogi', 'Request the Woo registration'),
	}
}

/**
 * The groups an administrator can pick, from OCS `cloud/groups/details`.
 *
 * @param {object} response The OCS response body.
 * @return {Array<{id: string, label: string}>} The options, by name.
 */
export function groupOptions(response) {
	const groups = response?.ocs?.data?.groups
	if (!Array.isArray(groups)) {
		return []
	}
	return groups
		.filter((group) => typeof group?.id === 'string' && group.id !== '')
		.map((group) => ({ id: group.id, label: group.displayname || group.id }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * One row per right, with its label and its granted groups as options.
 *
 * A granted group the instance no longer has stays in the row under its id,
 * so saving the row does not drop it unseen.
 *
 * @param {object} data The server's `{rights: [{verb, description, groups}]}`.
 * @param {Array<{id: string, label: string}>} options The groups to pick from.
 * @param {function(string, string, object=): string} t The translate function.
 * @return {Array<{verb: string, label: string, selected: Array<{id: string, label: string}>}>} The rows.
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
export function rightsRows(data, options, t) {
	const labels = rightLabels(t)
	const rights = Array.isArray(data?.rights) ? data.rights : []
	return rights.map((right) => ({
		verb: right.verb,
		label: labels[right.verb] || right.description || right.verb,
		selected: (Array.isArray(right.groups) ? right.groups : [])
			.map((id) => options.find((option) => option.id === id) || { id, label: id }),
	}))
}

/**
 * The body of a grant: exactly the picked group ids.
 *
 * @param {Array<{id: string}>} selected The picked groups.
 * @return {{groups: Array<string>}} The body.
 */
export function grantPayload(selected) {
	return { groups: (selected || []).map((option) => option.id) }
}

/**
 * What to tell the administrator when reading or saving failed.
 *
 * @param {object} error The axios error.
 * @param {function(string, string, object=): string} t The translate function.
 * @return {string} The sentence.
 */
export function rightsRefusal(error, t) {
	const status = error?.response?.status
	if (status === 401 || status === 403) {
		return t('opencatalogi', 'Only an administrator can see and change these rights.')
	}
	if (status === 400 && typeof error.response.data?.error === 'string') {
		return error.response.data.error
	}
	return t('opencatalogi', 'The rights could not be read or saved. Try again later.')
}
