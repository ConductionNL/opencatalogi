/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The id a register or schema reference names: an embedded object's id or
 * uuid, or the reference itself.
 *
 * @param {object|string|number|null} ref - The reference
 * @return {string|number|undefined} The id
 */
function referenceId(ref) {
	return typeof ref === 'object' ? ref?.id || ref?.uuid : ref
}

/**
 * The register and schema ids an object names on its `@self`.
 *
 * @param {object} object - An OpenRegister object with `@self`
 * @return {{registerId: string|number|undefined, schemaId: string|number|undefined}} The ids
 * @spec exclude id plumbing; no domain behavior of its own.
 */
export function registerSchemaIdsOf(object) {
	return {
		registerId: referenceId(object['@self'].register),
		schemaId: referenceId(object['@self'].schema),
	}
}
