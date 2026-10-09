/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the Directory page shows per listing about its synchronisation
 * (REQ-FLS-002). The listing carries `lastSync` (the last attempt),
 * `lastSuccessAt` (the last successful sync) and `lastError` (the error of the
 * last failed attempt, written by DirectoryService).
 */

/**
 * Derive the sync status shown on a listing row.
 *
 * - `lastSuccessAt`: the last successful sync, or null.
 * - `lastAttemptAt`: the last attempt, only when it differs from the last
 *   success (a healthy listing shows no separate attempt).
 * - `error`: the error of the last attempt, only when that attempt failed; an
 *   error left over from before the last success is not shown.
 * - `neverSucceeded`: true when no successful sync is recorded.
 *
 * @param {object|null|undefined} listing The listing object from the listings API.
 * @return {{lastSuccessAt: string|null, lastAttemptAt: string|null, error: string, neverSucceeded: boolean}}
 * @spec openspec/changes/federation-connection-last-success/specs/federation-connection-last-success/spec.md#requirement-the-directory-page-shows-when-each-connection-last-worked-req-fls-002
 */
export function listingSyncStatus(listing) {
	const source = listing || {}
	const lastSuccessAt = source.lastSuccessAt || null
	const lastSync = source.lastSync || null
	const lastAttemptAt = lastSync !== null && lastSync !== lastSuccessAt ? lastSync : null
	const error = lastAttemptAt !== null && typeof source.lastError === 'string' ? source.lastError : ''

	return {
		lastSuccessAt,
		lastAttemptAt,
		error,
		neverSucceeded: lastSuccessAt === null,
	}
}
