/**
 * Named handlers for the Retention review page.
 *
 * CnIndexPage calls a row action with `{ actionId, item }`, a bulk action with
 * `{ actionId, selectedIds, count }` and a header action with `{ actionId }`.
 * The action id is the retention decision: `extend`, `depublish` or `archive`.
 *
 * @param {object} deps The stores and helpers the handlers drive.
 * @param {object} deps.navigationStore The app's navigation store.
 * @param {(path: string) => string} deps.generateUrl Builds an app URL.
 * @param {(url: string) => void} deps.assign Opens a URL in this window.
 * @return {object} Handlers keyed by the name the manifest uses.
 *
 * @spec openspec/changes/retention-review-queue/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
 */
export function createRetentionActionHandlers({
	navigationStore,
	generateUrl,
	assign,
}) {
	return {
		/**
		 * Open the decision dialog on the clicked row or on the selection.
		 *
		 * @param {{actionId: string, item?: object, selectedIds?: Array<string>}} payload The action payload.
		 * @return {void}
		 *
		 * @spec openspec/changes/retention-review-queue/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
		 */
		openRetentionDecision({ actionId, item, selectedIds = [] }) {
			navigationStore.setDialog('retentionDecision', {
				decision: actionId,
				ids: item ? [String(item.id)] : selectedIds.map(String),
				publications: item ? [item] : [],
			})
		},

		/**
		 * Download the retention report.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
		 */
		downloadRetentionReport() {
			assign(generateUrl('/apps/opencatalogi/api/retention/report'))
		},
	}
}
