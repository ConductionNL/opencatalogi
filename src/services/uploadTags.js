/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The labels the upload dialog offers for attachments: the tags OpenRegister
 * knows, plus the labels on the current attachments and the tags kept in the
 * object store.
 */

/**
 * The publication attachments in the store; none when the collection is
 * missing or cannot be read.
 *
 * @param {object} store The object store.
 * @return {Array<object>} The attachments.
 */
function attachmentObjects(store) {
	try {
		const attCol =
			store.getCollection && store.getCollection('publicationAttachments')
		return Array.isArray(attCol?.results) ? attCol.results : []
	} catch {
		return []
	}
}

/**
 * The labels on the publication attachments in the store; none when the
 * collection is missing or cannot be read.
 *
 * @param {object} store The object store.
 * @return {Array<string>} The labels, possibly repeated.
 */
function attachmentLabels(store) {
	return attachmentObjects(store).flatMap((att) =>
		Array.isArray(att?.labels) ? att.labels : [],
	)
}

/**
 * The tags kept in the store's `tags` collection; none when it is missing or
 * cannot be read.
 *
 * @param {object} store The object store.
 * @return {Array<string>} The tags.
 */
function storedTags(store) {
	try {
		const tags = store.getCollection && store.getCollection('tags')
		return Array.isArray(tags) ? tags : []
	} catch {
		return []
	}
}

/**
 * Every known tag once, sorted: the fetched tags, the labels on the current
 * attachments and the tags kept in the store.
 *
 * @param {unknown} fetched The tags endpoint response; anything but an array counts as none.
 * @param {object} store The object store.
 * @return {Array<string>} The sorted tags.
 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
 */
export function knownTags(fetched, store) {
	const tagSet = new Set(Array.isArray(fetched) ? fetched : [])
	for (const label of attachmentLabels(store)) tagSet.add(label)
	for (const tag of storedTags(store)) tagSet.add(tag)
	return Array.from(tagSet).sort()
}

/**
 * The label select options: with the "no label" option first for choosing a
 * label, and the tags alone for editing them.
 *
 * @param {Array<string>} tags The sorted tags.
 * @param {string} noLabel The "no label" option.
 * @return {{options: Array<string>, edit: Array<string>}} The option lists.
 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
 */
export function labelOptionLists(tags, noLabel) {
	return {
		options: [noLabel, ...tags.filter((tag) => tag !== noLabel)],
		edit: [...tags],
	}
}

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
 * The tags the dialog reports when it closes: its latest tags, else the tags
 * kept in the store, else none.
 *
 * @param {unknown} latestTags The dialog's latest tags.
 * @param {object} store The object store.
 * @return {Array<string>} The tags.
 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
 */
export function closingTags(latestTags, store) {
	if (isNonEmptyArray(latestTags)) {
		return latestTags
	}
	const stored = store.getCollection && store.getCollection('tags')
	return isNonEmptyArray(stored) ? stored : []
}
