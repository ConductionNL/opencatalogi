/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The name an object modal shows for an OpenRegister object.
 */

/**
 * The object's `@self` name, else its own name, else its `@self` title, else
 * its id; undefined when it has none of those.
 *
 * @param {object|null|undefined} item The object.
 * @return {string|number|undefined} The name to show.
 */
export function objectDisplayName(item) {
	return (
		item?.['@self']?.name
		|| item?.name
		|| item?.['@self']?.title
		|| item?.id
	)
}
