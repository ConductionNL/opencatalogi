/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The row action shared by the publication and attachment lists: make the
 * clicked row the active object of its type, then open the modal or dialog
 * the action names.
 */

import { navigationStore, objectStore } from '../store/store.js'

/**
 * Make `item` the active object of `target.type` and open what `target` names.
 *
 * @param {object} item The clicked row.
 * @param {{type: string, modal?: string, dialog?: string, properties?: object}} target
 *   The object type, and the modal, or else the dialog (with its properties
 *   when it takes any), to open.
 * @return {void}
 */
export function openForObject(item, target) {
	objectStore.setActiveObject(target.type, item)
	if (target.modal) {
		navigationStore.setModal(target.modal)
		return
	}
	openDialog(target)
}

/**
 * Open the dialog `target` names, passing its properties only when it has them.
 *
 * @param {{dialog: string, properties?: object}} target The dialog to open.
 * @return {void}
 */
function openDialog(target) {
	if (target.properties) {
		navigationStore.setDialog(target.dialog, target.properties)
		return
	}
	navigationStore.setDialog(target.dialog)
}
