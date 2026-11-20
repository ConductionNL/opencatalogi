/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publish a publication through its lifecycle from the browser: OpenRegister's
 * transition route runs `publish` (from approved) or `publishWithoutReview`
 * (from draft) with the publication date in the same call. OpenRegister checks
 * the move, the caller's rights and the review setting, so a refused move
 * writes no date (publication-lifecycle-on-or REQ-PLC-003).
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The stored states from which a publish is a lifecycle transition. */
const UNPUBLISHED_STATES = ['', 'draft', 'in_review', 'approved']

/**
 * Whether publishing this object is a lifecycle transition: it carries a
 * stored lifecycle state that is not yet `published`. An object without a
 * `status`, or one already published (scheduled or withdrawn), keeps the
 * plain date write.
 *
 * @param {object} obj The selected object.
 * @return {boolean} True when the publish runs a transition.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */
export function publishesThroughLifecycle(obj) {
	if (!obj || typeof obj !== 'object' || !Object.hasOwn(obj, 'status')) {
		return false
	}

	return UNPUBLISHED_STATES.includes(String(obj.status ?? '').trim())
}

/**
 * The transition that publishes from the object's stored state.
 *
 * @param {object} obj The selected object.
 * @return {string} `publish` from approved, else `publishWithoutReview`.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */
export function publishActionFor(obj) {
	return String(obj?.status ?? '').trim() === 'approved' ? 'publish' : 'publishWithoutReview'
}

/**
 * Run the publish transition with the publication date through OpenRegister.
 *
 * @param {object} obj The selected object.
 * @param {string} publicationDate The publication date to write.
 * @return {Promise<object>} The object as OpenRegister answers it; rejects when the move is refused.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */
export async function publishThroughLifecycle(obj, publicationDate) {
	const id = obj?.['@self']?.id ?? obj?.id
	const { data } = await axios.post(
		generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(id)}/transition`),
		{ action: publishActionFor(obj), data: { publicationDate, depublicationDate: null } },
	)

	return data
}
