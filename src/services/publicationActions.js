/**
 * Named row-action handlers for the Publications index.
 *
 * CnIndexPage resolves a manifest action's `handler` string against the
 * `customComponents` map and calls the function with
 * `{ actionId, item }`, where `item` is the clicked row.
 *
 * @param {object} deps The stores the handlers drive.
 * @param {object} deps.objectStore The app's object store.
 * @param {object} deps.navigationStore The app's navigation store.
 * @return {object} Handlers keyed by the name the manifest uses.
 *
 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
 */
export function createPublicationActionHandlers({ objectStore, navigationStore }) {
	return {
		/**
		 * Open the publication in the viewObject modal on its Files tab. The
		 * index row is the full OpenRegister object, `@self` included, which
		 * the modal needs to treat it as existing and to fetch its files.
		 *
		 * @param {{item: object}} payload The action payload.
		 * @return {void}
		 *
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		openPublicationFiles({ item }) {
			objectStore.setActiveObject('publication', item)
			navigationStore.setTransferData({ initialTab: 'files' })
			navigationStore.setModal('viewObject')
		},
	}
}
