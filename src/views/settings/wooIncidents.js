/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo pipeline failures in the admin settings (woo-national-output-assurance,
 * REQ-WNO-004): who receives them, and the ones still open.
 */

/**
 * The endpoint path of the incident group and the open incidents.
 */
export const WOO_INCIDENTS_PATH = '/apps/opencatalogi/api/woo/incidents'

/**
 * The endpoint path that sets the incident group.
 */
export const WOO_INCIDENT_GROUP_PATH = '/apps/opencatalogi/api/woo/incidents/group'

/**
 * Read the server's answer fail-closed: no group and no incidents unless the answer says so.
 *
 * @param {object|null} data The answer of the incidents endpoint.
 * @return {{group: string, incidents: Array<{id: string, kind: string, subject: string, message: string, at: string}>, error: string}} The state.
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
export function incidentStateFrom(data) {
	const incidents = Array.isArray(data?.incidents) ? data.incidents : []

	return {
		group: typeof data?.group === 'string' ? data.group : '',
		incidents: incidents
			.filter((incident) => incident && typeof incident === 'object')
			.map((incident) => ({
				id: String(incident.id ?? ''),
				kind: String(incident.kind ?? ''),
				subject: String(incident.subject ?? ''),
				message: String(incident.message ?? ''),
				at: String(incident.at ?? ''),
			})),
		error: typeof data?.error === 'string' ? data.error : '',
	}
}

/**
 * Whether the settings must warn that nobody receives pipeline failures.
 *
 * @param {{group: string}|null} state The state.
 * @return {boolean} True while no group is set.
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
export function nobodyReceives(state) {
	return !state || state.group.trim() === ''
}

/**
 * The one line an open incident is listed with.
 *
 * @param {{kind: string, subject: string, message: string, at: string}} incident The incident.
 * @param {function(string): string} formatDate Formats the moment.
 * @return {string} The line.
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
export function incidentLine(incident, formatDate) {
	const at = incident.at === '' ? '' : formatDate(incident.at) + ': '

	return at + incident.message
}
