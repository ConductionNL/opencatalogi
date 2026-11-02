/**
 * Named row-action handlers for the Products and services page.
 *
 * CnIndexPage resolves a manifest action's `handler` string against the
 * `customComponents` map and calls it with `{ actionId, item }`.
 *
 * @param {object} deps The stores the handlers drive.
 * @param {object} deps.navigationStore The app's navigation store.
 * @return {object} Handlers keyed by the name the manifest uses.
 *
 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
 */
export function createCaseTypeActionHandlers({ navigationStore }) {
	return {
		/**
		 * Open the resync of the case type the entry's form binding names.
		 *
		 * @param {{item: object}} payload The action payload.
		 * @return {void}
		 *
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		openCaseTypeResync({ item }) {
			navigationStore.setDialog('caseTypeResync', {
				caseType: item?.formBinding?.caseType || '',
				entryTitle: item?.title || '',
			})
		},
	}
}
