/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The OpenRegister object endpoints of a register and schema the app is
 * configured with. A dialog names the two initial-state keys that hold them.
 */

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

/**
 * The configured register and schema, read from the initial state.
 *
 * @param {{register: string, schema: string}} stateKeys The initial-state keys.
 * @return {{register: string, schema: string}} The register and the schema.
 */
function configuredRegisterSchema(stateKeys) {
	return {
		register: loadState('opencatalogi', stateKeys.register, ''),
		schema: loadState('opencatalogi', stateKeys.schema, ''),
	}
}

/**
 * The list endpoint of the configured register and schema.
 *
 * @param {{register: string, schema: string}} stateKeys The initial-state keys.
 * @return {string} The URL.
 */
export function configuredObjectsUrl(stateKeys) {
	return generateUrl(
		'/apps/openregister/api/objects/{register}/{schema}',
		configuredRegisterSchema(stateKeys),
	)
}

/**
 * The endpoint of one object in the configured register and schema.
 *
 * @param {{register: string, schema: string}} stateKeys The initial-state keys.
 * @param {string} id The object.
 * @return {string} The URL.
 */
export function configuredObjectUrl(stateKeys, id) {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', {
		...configuredRegisterSchema(stateKeys),
		id,
	})
}
