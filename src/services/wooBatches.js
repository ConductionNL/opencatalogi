// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Add on the Woo batches page (REQ-CVS-004). The index's create dialog asks
// only for the case reference; this handler opens the batch through
// POST /api/woo/batches, which gathers the documents delivered for that case
// and refuses a second open batch for the same reference, naming it.

/**
 * The create-override handlers of the Woo batches index.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.axios The HTTP client.
 * @param {(path: string) => string} deps.generateUrl Builds an app URL.
 * @return {{createWooBatch: (formData: object) => Promise<object>}} The handlers, keyed by the name the manifest uses.
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-transparency/spec.md#requirement-opening-a-batch-gathers-the-documents-of-the-woo-request-req-cvs-004
 */
export function createWooBatchHandlers({ axios, generateUrl }) {
	return {
		/**
		 * Open a batch for the case reference in the form.
		 *
		 * @param {object} formData The create form, carrying `caseReference`.
		 * @return {Promise<object>} The batch the server opened.
		 * @throws {Error} With the server's refusal, e.g. the open batch it names.
		 */
		async createWooBatch(formData) {
			const caseReference = String(formData?.caseReference || '').trim()
			try {
				const { data } = await axios.post(generateUrl('/apps/opencatalogi/api/woo/batches'), { caseReference })
				return data
			} catch (e) {
				throw new Error(e?.response?.data?.error || e?.message || '', { cause: e })
			}
		},
	}
}
