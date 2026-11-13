/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings line for the publish right (operations-publish-rights,
 * REQ-OPR-004): what the administrator reads about the publishers group.
 */

/**
 * The sentences for the publishers group: whether it exists and how many
 * members it has.
 *
 * @param {{group: string, exists: boolean, members: number}|null} status The group's state from the server, null when it could not be read.
 * @param {function(string, string, object=): string} t The translate function.
 * @return {Array<string>} The sentences, in reading order.
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */
export function publishRightsLines(status, t) {
	if (!status) {
		return [t('opencatalogi', 'The publish rights could not be read.')]
	}

	const lines = [
		t(
			'opencatalogi',
			'Only members of the group {group} and administrators may publish and withdraw publications.',
			{ group: status.group },
		),
	]
	if (!status.exists) {
		lines.push(
			t(
				'opencatalogi',
				'The group does not exist yet. Create it in user management, or run the app\'s repair step.',
			),
		)
		return lines
	}

	lines.push(t('opencatalogi', 'Members: {count}', { count: status.members }))
	if (status.members === 0) {
		lines.push(
			t(
				'opencatalogi',
				'The group has no members yet, so only administrators can publish.',
			),
		)
	}
	return lines
}
