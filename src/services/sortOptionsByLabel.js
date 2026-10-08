/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Return a copy of select options sorted alphanumerically on `label`:
 * case-insensitive and natural ("Schema 2" before "Schema 10"). A missing
 * label sorts as an empty string. The input array is left untouched.
 *
 * @param {Array<{label?: string}>} options - The select options to sort.
 * @return {Array<{label?: string}>} A new, sorted array.
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export function sortOptionsByLabel(options) {
	if (!Array.isArray(options)) return []
	return [...options].sort((a, b) =>
		String(a?.label ?? '').localeCompare(String(b?.label ?? ''), undefined, {
			sensitivity: 'base',
			numeric: true,
		}),
	)
}
