/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The officer's order of a publication's documents (REQ-PDP-001): the stored
 * `attachmentOrder` first, then the rest as the files endpoint lists them.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-an-officer-orders-a-publication-s-documents-and-the-public-list-keeps-that-order-req-pdp-001
 */

/**
 * The documents in the stored order, then the documents not in it, in their own order.
 * An id in the order that is no longer attached is skipped.
 *
 * @param {Array<{id: (string|number), label: string}>} documents The documents.
 * @param {Array<(string|number)>} order The stored `attachmentOrder`.
 * @return {Array<{id: string, label: string}>} The documents, ids as strings.
 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
 */
export function orderedDocuments(documents, order) {
	const byId = new Map(
		documents.map((doc) => [
			String(doc.id),
			{ id: String(doc.id), label: doc.label },
		]),
	)
	const ordered = []
	for (const id of Array.isArray(order) ? order : []) {
		const doc = byId.get(String(id))
		if (doc) {
			ordered.push(doc)
			byId.delete(String(id))
		}
	}

	return ordered.concat([...byId.values()])
}

/**
 * The list with one document moved one place up (-1) or down (+1).
 * A move past either end leaves the list as it is.
 *
 * @param {Array<object>} documents The documents.
 * @param {number} index The position of the document, from 0.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Array<object>} A new list.
 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
 */
export function moveDocument(documents, index, delta) {
	const target = index + delta
	if (
		index < 0
		|| index >= documents.length
		|| target < 0
		|| target >= documents.length
	) {
		return documents.slice()
	}

	const moved = documents.slice()
	const [doc] = moved.splice(index, 1)
	moved.splice(target, 0, doc)
	return moved
}

/**
 * The `attachmentOrder` to store for a list.
 *
 * @param {Array<{id: string}>} documents The documents in order.
 * @return {Array<string>} Their ids.
 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
 */
export function storedOrder(documents) {
	return documents.map((doc) => String(doc.id))
}
