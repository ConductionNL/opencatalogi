/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A default value as an entity receives it: lists and objects are copied, so
 * two entities never share one default.
 *
 * @template T
 * @param {T} value - The default from a defaults table
 * @return {T} The value, or a shallow copy of a list or object
 */
function freshDefault(value) {
	if (Array.isArray(value)) {
		return [...value]
	}
	if (value !== null && typeof value === 'object') {
		return { ...value }
	}
	return value
}

/**
 * Read every field of a defaults table from raw entity data, keeping the
 * default wherever the data's value is falsy (the `data?.field || default`
 * rule). The fields come out in the order of the table.
 *
 * @template {object} T
 * @param {unknown} data - The raw entity data, possibly missing
 * @param {T} defaults - The fields to read, each with its default
 * @return {T} The fields with their values
 */
export function fillDefaults(data, defaults) {
	const source = data || {}
	const filled = {}
	for (const [key, fallback] of Object.entries(defaults)) {
		filled[key] = source[key] || freshDefault(fallback)
	}
	return filled
}

/**
 * The value when it is a truthy non-array, otherwise the fallback. The
 * backend (PHP) does not know empty objects, so it sends an empty array.
 *
 * @template T
 * @param {unknown} value - The value from the entity data
 * @param {T} fallback - What to use instead
 * @return {T} The value or the fallback
 */
export function objectOr(value, fallback) {
	return (!Array.isArray(value) && value) || fallback
}
