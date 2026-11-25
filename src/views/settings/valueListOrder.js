/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading a value list in the organisation's order and saving a move, shared
 * by the Woo settings sections that order a list (REQ-WVL-002).
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The URL of the value-list choices.
 */
export const CHOICES_URL = '/apps/opencatalogi/api/woo/value-list-choices'

/**
 * The URL that saves the order of a list.
 */
export const ORDER_URL = '/apps/opencatalogi/api/woo/value-list-choices/order'

/**
 * The entries with one moved a place up or down, or null when it cannot move.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 * @param {Array<object>} entries The entries in their current order.
 * @param {number} index The place of the entry to move.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Array<object>|null} The entries in their new order.
 */
export function moveEntry(entries, index, delta) {
	const target = index + delta
	if (
		index < 0
		|| index >= entries.length
		|| target < 0
		|| target >= entries.length
	) {
		return null
	}
	const moved = [...entries]
	const [entry] = moved.splice(index, 1)
	moved.splice(target, 0, entry)
	return moved
}

/**
 * Read one list with the organisation's choices applied.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 * @param {string} scheme The list, e.g. `soortHandeling`.
 * @return {Promise<object>} The answer: `{entries, choicesUnavailable}`.
 */
export async function fetchList(scheme) {
	const { data } = await axios.get(generateUrl(CHOICES_URL), {
		params: { scheme },
	})
	return data
}

/**
 * Move an entry one place and save the list's new order.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 * @param {string} scheme The list.
 * @param {Array<object>} entries The entries in their current order.
 * @param {number} index The place of the entry to move.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Promise<{data: object, announcement: string}|null>} The server's answer and the live-region text, or null when the entry cannot move.
 */
export async function saveMove(scheme, entries, index, delta) {
	const moved = moveEntry(entries, index, delta)
	if (moved === null) {
		return null
	}
	const { data } = await axios.put(generateUrl(ORDER_URL), {
		scheme,
		entries: moved.map((item) => item.uri),
	})
	return {
		data,
		announcement: t('opencatalogi', '{label} is now at position {position}.', {
			label: entries[index].label,
			position: index + delta + 1,
		}),
	}
}
