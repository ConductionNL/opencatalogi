/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The requests of the instance mode section: read the mode and what
 * OpenCatalogi does not stop, and change the mode once confirmed
 * (instance-staging-mode REQ-STG-001).
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The two modes an administrator can choose. */
export const INSTANCE_MODES = ['production', 'staging']

/**
 * The instance mode endpoint.
 *
 * @return {string} The URL.
 */
export function instanceModeUrl() {
	return generateUrl('/apps/opencatalogi/api/settings/instance-mode')
}

/**
 * The section's state from a backend answer: the mode and the named outside channels.
 *
 * @param {object} data The answer.
 * @return {{mode: string, webhooks: Array<string>, synchronisations: Array<string>}} The state.
 */
export function instanceModeFrom(data) {
	const outside = data?.outside || {}
	return {
		mode: INSTANCE_MODES.includes(data?.mode) ? data.mode : 'staging',
		webhooks: Array.isArray(outside.webhooks) ? outside.webhooks : [],
		synchronisations: Array.isArray(outside.synchronisations) ? outside.synchronisations : [],
	}
}

/**
 * Read the instance mode.
 *
 * @return {Promise<{mode: string, webhooks: Array<string>, synchronisations: Array<string>}>} The state.
 */
export async function fetchInstanceMode() {
	const { data } = await axios.get(instanceModeUrl())
	return instanceModeFrom(data)
}

/**
 * Change the instance mode; the administrator has confirmed it.
 *
 * @param {string} mode production or staging.
 * @return {Promise<object>} The answer: the mode and the previous one.
 */
export async function saveInstanceMode(mode) {
	const { data } = await axios.post(instanceModeUrl(), { mode, confirmed: true })
	return data
}
