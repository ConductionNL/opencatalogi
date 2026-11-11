/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The requests the federation listing modals send to the listings API, and
 * the error a refused answer becomes.
 */

/**
 * The URL of one listing.
 *
 * @param {string} listingId - The listing id
 * @return {string} The URL
 */
function listingUrl(listingId) {
	return generateUrl(`/apps/opencatalogi/api/listings/${listingId}`)
}

/**
 * The JSON body of an answer, or an empty object when it has none.
 *
 * @param {Response} res - The answer
 * @return {Promise<object>} The body
 */
function answerBody(res) {
	return res.json().catch(() => ({}))
}

/**
 * The error of a refused listing answer: the API's error or message, else
 * the HTTP status.
 *
 * @param {object} body - The answer body
 * @param {number} status - The HTTP status
 * @return {Error} The error
 */
function listingError(body, status) {
	return new Error(
		body?.data?.error || body?.error || body?.message || `HTTP ${status}`,
	)
}

/**
 * Send a JSON payload to the listings API and read the answer.
 *
 * @param {string} endpoint - The URL
 * @param {{method: string, payload: object}} request - The HTTP method and the payload
 * @return {Promise<{res: Response, body: object}>} The answer and its body
 */
async function sendListingJson(endpoint, { method, payload }) {
	const res = await fetch(endpoint, {
		method,
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: JSON.stringify(payload),
	})
	const body = await answerBody(res)
	return { res, body }
}

/**
 * Set a listing's integration level.
 *
 * @param {string} listingId - The listing id
 * @param {string|undefined} integrationLevel - The integration level
 * @return {Promise<object>} The updated listing
 * @throws {Error} When the update is refused
 */
export async function updateListingLevel(listingId, integrationLevel) {
	const { res, body } = await sendListingJson(listingUrl(listingId), {
		method: 'PUT',
		payload: { integrationLevel },
	})
	if (!res.ok) {
		throw listingError(body, res.status)
	}
	return body
}

/**
 * Delete a listing.
 *
 * @param {string} listingId - The listing id
 * @return {Promise<void>}
 * @throws {Error} When the delete is refused
 */
export async function deleteListing(listingId) {
	const res = await fetch(listingUrl(listingId), {
		method: 'DELETE',
		headers: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	if (!res.ok) {
		throw listingError(await answerBody(res), res.status)
	}
}

/**
 * Add a peer directory by its URL.
 *
 * @param {string} url - The directory URL
 * @return {Promise<object>} The answer
 * @throws {Error} With the API's message, else the HTTP status, when refused
 */
export async function addDirectory(url) {
	const endpoint = generateUrl('/apps/opencatalogi/api/listings/add')
	const { res, body } = await sendListingJson(endpoint, {
		method: 'POST',
		payload: { url },
	})
	if (!res.ok) {
		throw new Error(body.message || `HTTP ${res.status}`)
	}
	return body
}
