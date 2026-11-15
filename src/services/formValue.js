/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Resolve a property's effective value in an object form: the edited value in
 * `formData` when the form holds the key, otherwise the persisted value.
 *
 * The `in` check goes through the reactive proxy's `has` trap, so a computed
 * that reads a key the form does not hold yet re-runs once it is added.
 * `Object.hasOwn` alone is not tracked by Vue (#2370); it is kept to exclude
 * prototype keys such as `constructor`.
 *
 * @param {object} formData - The (reactive) form state.
 * @param {object|null|undefined} fallback - The persisted object, if any.
 * @param {string} key - The property key.
 * @return {unknown} The effective value.
 * @spec openspec/specs/retrofit-2026-05-26-object-modals/spec.md#requirement-schema-driven-form-editing-req-objm-002
 */
export function formValue(formData, fallback, key) {
	return key in formData && Object.hasOwn(formData, key)
		? formData[key]
		: fallback?.[key]
}
