/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Check if a string is a valid ISO date string
 *
 * @param {string} dateString - The date string to validate
 * @return {boolean} True if the string is a valid ISO date string
 *
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default function getValidISOstring(dateString) {
	if (!dateString || typeof dateString !== 'string') {
		return false
	}

	// Check if it matches ISO 8601 format (with timezone offset support)
	const isoDateRegex =
		/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?(Z|[+-]\d{2}:\d{2})?$/
	if (!isoDateRegex.test(dateString)) {
		return false
	}

	// Check if it's a valid date
	const date = new Date(dateString)
	return date instanceof Date && !isNaN(date.getTime())
}
