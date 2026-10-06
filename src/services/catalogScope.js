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
 */
export function normaliseIdList(raw) {
	let list = raw
	if (typeof list === 'string') {
		try {
			list = JSON.parse(list)
		} catch {
			return []
		}
	}
	if (!Array.isArray(list)) {
		return []
	}

	const ids = []
	for (const value of list) {
		let id = null
		if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
			id = value
		} else if (typeof value === 'string' && /^\s*\d+\s*$/.test(value)) {
			id = Number(value)
		}
		if (id !== null && id > 0 && !ids.includes(id)) {
			ids.push(id)
		}
	}
	return ids
}

/**
 * The query-string spelling of a pair, e.g. `19-173`.
 *
 * @param {{register: number, schema: number}} pair The pair.
 * @return {string} The key.
 */
export function pairKey(pair) {
	return `${pair.register}-${pair.schema}`
}

/**
 * Every register × schema pair of a catalog, registers first.
 *
 * @param {{registers?: Array<number|string>|string, schemas?: Array<number|string>|string}|null} catalog The catalog.
 * @return {Array<{register: number, schema: number, key: string}>} The pairs; empty when either side has no numeric id.
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
