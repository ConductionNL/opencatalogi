/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which objects a selection list shows: the objects passed in, else the
 * selected attachments, else the selected objects in the object store.
 */

/**
 * Whether a value is an array with at least one entry.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a non-empty array.
 */
function isNonEmptyArray(value) {
	return Array.isArray(value) && value.length > 0
}

/**
 * The publication attachments collection of the store: the one kept in
 * `collections`, else the one `getCollection()` returns.
 *
 * @param {object} store The object store.
 * @return {Array<object>|{results: Array<object>}|null} The collection.
 */
function attachmentsCollection(store) {
	if (store.collections && store.collections.publicationAttachments) {
		return store.collections.publicationAttachments
	}
	return typeof store.getCollection === 'function'
		? store.getCollection('publicationAttachments')
		: null
}

/**
 * The attachments in the store's attachments collection.
 *
 * @param {object} store The object store.
 * @return {Array<object>|null} The attachments, or null when there are none.
 */
function storedAttachments(store) {
	const collection = attachmentsCollection(store)
	const attachments = collection?.results || collection || []
	return isNonEmptyArray(attachments) ? attachments : null
}

/**
 * The selected attachments (ids in `selectedAttachments`) as full objects from
 * the attachments collection.
 *
 * @param {object} store The object store.
 * @return {Array<object>|null} The selected attachments, or null when nothing is selected or the collection is empty.
 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
 */
export function selectedAttachmentObjects(store) {
	const selectedAttachmentIds = store.selectedAttachments || []
	if (!isNonEmptyArray(selectedAttachmentIds)) {
		return null
	}
	const attachments = storedAttachments(store)
	if (!attachments) {
		return null
	}
	return attachments.filter((att) => selectedAttachmentIds.includes(att.id))
}

/**
 * The objects a selection list shows: `objects` when it is an array, else the
 * selected attachments, else the non-empty `selectedObjects` of the store,
 * else none.
 *
 * @param {Array<object>|null|undefined} objects The objects passed to the list.
 * @param {object} store The object store.
 * @return {Array<object>} The objects to show.
 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
 */
export function resolveSelectedObjects(objects, store) {
	if (Array.isArray(objects)) {
		return objects
	}
	const attachments = selectedAttachmentObjects(store)
	if (attachments) {
		return attachments
	}
	return isNonEmptyArray(store.selectedObjects) ? store.selectedObjects : []
}
