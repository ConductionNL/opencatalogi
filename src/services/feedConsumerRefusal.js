/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a refused Feed consumers call tells the administrator: the server's
 * own reason (already translated there), else the transport's message.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The refusal of a failed request as one line.
 *
 * @param {object} e The axios error.
 * @return {string} The line.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
export function refusalOf(e) {
	const reason = e?.response?.data?.error || e?.response?.data?.message
	if (typeof reason === 'string' && reason !== '') {
		return reason
	}
	return `${t('opencatalogi', 'Error:')} ${e?.message || ''}`.trim()
}
