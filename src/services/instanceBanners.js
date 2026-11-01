/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The instance banners a signed-in user sees (REQ-PCS-103). The server owns
 * both rules: which banners are inside their period, and which ones this user
 * dismissed (NoticeBoardController::banners and ::dismissBanner). This module
 * only reads and sends.
 */

/**
 * The banners to show to the signed-in user now.
 *
 * A banner without an id or a body is dropped. When the banners cannot be
 * read, nothing is shown: a banner is an announcement, and a failed read must
 * not block the app.
 *
 * @param {{get: (url: string) => Promise<{data: object}>}} http An axios-like client.
 * @param {(path: string) => string} generateUrl Builds an app URL from a path.
 * @return {Promise<Array<{id: string, body: string, severity: string, dismissable: boolean}>>}
 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
export async function loadInstanceBanners(http, generateUrl) {
	let data
	try {
		data = (await http.get(generateUrl('/api/banners'))).data
	} catch {
		return []
	}

	const banners = Array.isArray(data?.banners) ? data.banners : []
	return banners
		.filter(
			(banner) =>
				banner
				&& banner.id
				&& typeof banner.body === 'string'
				&& banner.body.trim() !== '',
		)
		.map((banner) => ({
			id: String(banner.id),
			body: banner.body,
			severity: banner.severity || 'info',
			dismissable: banner.dismissable !== false,
		}))
}

/**
 * Tell the server this user dismissed a banner; it is not shown to them again.
 *
 * @param {{post: (url: string, body: object) => Promise<{data: object}>}} http An axios-like client.
 * @param {(path: string) => string} generateUrl Builds an app URL from a path.
 * @param {string} bannerId The banner's id.
 * @return {Promise<void>}
 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
export async function dismissInstanceBanner(http, generateUrl, bannerId) {
	await http.post(generateUrl('/api/banners/dismiss'), { banner: bannerId })
}

/**
 * The note card type for a banner severity.
 *
 * @param {string} severity One of info, warning, critical.
 * @return {string} The NcNoteCard type.
 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
export function noteTypeFor(severity) {
	if (severity === 'critical') return 'error'
	if (severity === 'warning') return 'warning'
	return 'info'
}
