/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The catalogue page's line on who sees and edits its publications.
 *
 * @param {{restricted?: boolean, readGroups?: Array<string>, editGroups?: Array<string>}|null|undefined} access The catalogue's staff access.
 * @return {string} The line.
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003
 */
export function staffAccessLine(access) {
	if (access?.restricted !== true) {
		return t('opencatalogi', 'All staff')
	}
	const groups = [
		...new Set([...(access.editGroups || []), ...(access.readGroups || [])]),
	]
	if (groups.length === 0) {
		return t('opencatalogi', 'Restricted to administrators')
	}
	return t('opencatalogi', 'Restricted to {groups}', { groups: groups.join(', ') })
}
