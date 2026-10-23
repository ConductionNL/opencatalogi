/**
 * Named action handlers for the Publications index.
 *
 * CnIndexPage resolves a manifest action's `handler` string against the
 * `customComponents` map. A row action is called with `{ actionId, item }`,
 * where `item` is the clicked row. A bulk action is called by
 * CatalogPublicationsIndex with `{ actionId, selectedIds, count, objects,
 * props }`, where `objects` are the selected rows and `props` the action's own
 * manifest props.
 *
 * @param {object} deps The stores and helpers the handlers drive.
 * @param {object} deps.objectStore The app's object store.
 * @param {object} deps.navigationStore The app's navigation store.
 * @param {(message: string) => void} [deps.showError] Shows a refusal to the user.
 * @param {(app: string, text: string) => string} [deps.t] Translates a message.
 * @return {object} Handlers keyed by the name the manifest uses.
 *
 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
 */
export function createPublicationActionHandlers({
	objectStore,
	navigationStore,
	showError = () => {},
	t = (app, text) => text,
}) {
	/**
	 * Stage the selected rows and open the dialog or modal `props` names.
	 *
	 * @param {{objects?: Array<object>, props?: {dialog?: string, modal?: string}}} payload The bulk payload.
	 * @return {boolean} Whether something opened.
	 *
	 * @spec openspec/changes/publications-selection-actions/specs/publication-selection-actions/spec.md#requirement-the-selection-bar-opens-the-mass-dialogs-on-the-selected-publications-psa-001
	 */
	function openSelectionDialog({ objects = [], props = {} }) {
		const target = props?.modal || props?.dialog
		if (!target || objects.length === 0) {
			return false
		}
		objectStore.setSelectedObjects([...objects])
		if (props.modal) {
			navigationStore.setModal(props.modal)
		} else {
			navigationStore.setDialog(props.dialog)
		}
		return true
	}

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

		openSelectionDialog,

		/**
		 * Open the merge dialog at "Configure merge", the first selected
		 * publication as the source and the second as the target. Any other
		 * number selected opens nothing and says why.
		 *
		 * @param {{objects?: Array<object>}} payload The bulk payload.
		 * @return {boolean} Whether the dialog opened.
		 *
		 * @spec openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#requirement-two-selected-publications-can-be-merged-from-the-list-req-pmm-001
		 */
		openPublicationMerge({ objects = [] }) {
			if (objects.length !== 2) {
				showError(
					t('opencatalogi', 'Select exactly two publications to merge'),
				)
				return false
			}
			navigationStore.setTransferData({
				mergeSource: objects[0],
				mergeTarget: objects[1],
				startStep: 2,
			})
			navigationStore.setModal('mergeObject')
			return true
		},

		/**
		 * Open the migration dialog with the selected publications.
		 *
		 * @param {{objects?: Array<object>}} payload The bulk payload.
		 * @return {boolean} Whether the dialog opened.
		 *
		 * @spec openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#requirement-selected-publications-can-be-migrated-to-another-schema-req-pmm-002
		 */
		openPublicationMigration({ objects = [] }) {
			return openSelectionDialog({
				objects,
				props: { modal: 'migrationObject' },
			})
		},
	}
}
