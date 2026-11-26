/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The version information the admin settings page starts with.
 *
 * The server hands it over with the page (`OpenCatalogiAdmin::getForm()`,
 * initial state `version-info`), so the version card is filled before the
 * page's own request answers. Without it the page starts empty and fills the
 * card from `GET /api/settings/version`, as before.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-10
 * @return {{appName: string, appVersion: string, configuredVersion: (string|null), versionsMatch: boolean, needsUpdate: boolean}} The version information
 */
export function initialVersionInfo() {
	const empty = {
		appName: '',
		appVersion: '',
		configuredVersion: null,
		versionsMatch: false,
		needsUpdate: false,
	}

	let provided
	try {
		provided = loadState('opencatalogi', 'version-info', null)
	} catch {
		// Unreadable initial state: the page fills the card from its own request.
		provided = null
	}

	if (provided === null || typeof provided !== 'object') {
		return empty
	}

	return { ...empty, ...provided }
}
