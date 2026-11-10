/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How a detail-page widget finds the object it is shown for. The two detail
 * render paths surface it differently: CnDetailPage's config grid provides a
 * `cnObjectContext` inject (a ref or a plain object), while the page renderer
 * provides the `cnDetailObjectContext` ref.
 */

/**
 * The injected object context: `cnObjectContext` (its value when it is a
 * ref), else the `cnDetailObjectContext` ref's value, else {}.
 *
 * @param {{cnObjectContext: object|null, cnDetailObjectContext: object|null}} vm The widget.
 * @return {object} The context.
 */
export function injectedObjectContext(vm) {
	const injected =
		vm.cnObjectContext && (vm.cnObjectContext.value || vm.cnObjectContext)
	const holder = vm.cnDetailObjectContext && vm.cnDetailObjectContext.value
	return injected || holder || {}
}

/**
 * A schema as the slug the cards want: a string as it is, an object by its
 * slug, name or id.
 *
 * @param {string|object|null} schema The schema.
 * @return {string|number} The slug, or ''.
 */
export function schemaSlug(schema) {
	return typeof schema === 'string'
		? schema
		: (schema && (schema.slug || schema.name || schema.id)) || ''
}
