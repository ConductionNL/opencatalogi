/**
 * Extract a routable id from an OpenRegister object row.
 *
 * Index-page rows arrive in variant shapes depending on which projection
 * the current @conduction/nextcloud-vue library variant emits: the
 * canonical id lives at `@self.id`, but the row can also carry a
 * top-level `id`, a `uuid`, or a fallback `@self.uuid`. Returns the
 * first non-empty value so downstream router pushes work regardless of
 * which shape the library currently uses.
 *
 * @param {object|null|undefined} obj Row payload from CnIndexPage.
 * @return {string|null} The resolved id, or null when nothing matches.
 * @spec exclude row-shape plumbing; no domain behavior of its own.
 */
export function resolveObjectId(obj) {
	return obj?.['@self']?.id
		|| obj?.id
		|| obj?.uuid
		|| obj?.['@self']?.uuid
		|| null
}
