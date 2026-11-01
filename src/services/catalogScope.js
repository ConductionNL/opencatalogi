// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A catalog's scope: the register/schema pairs its publications live in.
 *
 * Follows the backend's rule (`PublicationsController::normaliseIdList`) that
 * only numeric ids count, so slugs and free text in a catalog's
 * `registers`/`schemas` are ignored. It is stricter than PHP's `is_numeric`:
 * only positive whole numbers are ids here, so `1.5`, `-3` or `1e3` are not.
 */

/**
 * Normalise a catalog's `registers` or `schemas` field to numeric ids.
 *
 * Accepts an array of numbers and numeric strings, or that array as a JSON
 * string. Anything else is dropped, and each id is kept once.
 *
 * @param {Array<number|string>|string|null|undefined} raw The catalog field.
 * @return {Array<number>} The ids, in their original order.
 *
 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
 */
export function normaliseIdList(raw) {
	const list = parseIdList(raw)
	if (!Array.isArray(list)) {
		return []
	}

	const ids = list.map(toId).filter((id) => id !== null && id > 0)
	return [...new Set(ids)]
}

/**
 * Read a JSON-encoded list; anything that is not a string passes through.
 *
 * @param {unknown} raw The catalog field.
 * @return {unknown} The parsed value, or null when the string is not JSON.
 */
function parseIdList(raw) {
	if (typeof raw !== 'string') {
		return raw
	}
	try {
		return JSON.parse(raw)
	} catch {
		return null
	}
}

/**
 * One list entry as an id: a positive whole number or a string of digits.
 *
 * @param {unknown} value The entry.
 * @return {number|null} The id, or null when the entry is not one.
 */
function toId(value) {
	if (isPositiveInteger(value)) {
		return value
	}
	if (typeof value === 'string' && /^\s*\d+\s*$/.test(value)) {
		return Number(value)
	}
	return null
}

/**
 * @param {unknown} value The entry.
 * @return {boolean} Whether the entry is a positive whole number.
 */
function isPositiveInteger(value) {
	return typeof value === 'number' && Number.isInteger(value) && value > 0
}

/**
 * The query-string spelling of a pair, e.g. `19-173`.
 *
 * @param {{register: number, schema: number}} pair The pair.
 * @return {string} The key.
 *
 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
 */
export function pairKey(pair) {
	return `${pair.register}-${pair.schema}`
}

/**
 * Every register × schema pair of a catalog, registers first.
 *
 * @param {{registers?: Array<number|string>|string, schemas?: Array<number|string>|string}|null} catalog The catalog.
 * @return {Array<{register: number, schema: number, key: string}>} The pairs; empty when either side has no numeric id.
 *
 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
 */
export function catalogScopePairs(catalog) {
	const registers = normaliseIdList(catalog?.registers)
	const schemas = normaliseIdList(catalog?.schemas)
	const pairs = []
	for (const register of registers) {
		for (const schema of schemas) {
			pairs.push({ register, schema, key: pairKey({ register, schema }) })
		}
	}
	return pairs
}
