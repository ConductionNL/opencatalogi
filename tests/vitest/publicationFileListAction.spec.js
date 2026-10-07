/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Publications page "File list" row action (opencatalogi#1583).
 *
 * The manifest names its handler by string; CnIndexPage resolves that name
 * against the `customComponents` map through `dispatchAction`. A misspelled
 * name silently drops the handler and the menu entry does nothing, so this
 * spec runs the real manifest action through the library's dispatch against
 * the app's handler map, with the stores replaced by recorders.
 *
 * The menu order is checked the same way: the page's declared entries and
 * enabled built-ins go through the library's own resolver.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { createPublicationActionHandlers } from '../../src/services/publicationActions.js'

// The dispatch module's dependencies read `window` at load; the node
// environment has none.
globalThis.window ??= globalThis
const { dispatchAction } =
	await import('@conduction/nextcloud-vue/dist/esm/components/CnIndexPage/manifestActionDispatch.js')
const { buildDefaultActions } =
	await import('@conduction/nextcloud-vue/dist/esm/components/CnIndexPage/defaultActions.js')
const { resolveRowActions } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/resolveRowActions.js')
const { rowActionTestId } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/rowActionItem.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const publications = manifest.pages.find((p) => p.id === 'Publications')
// File list opens the publication modal, so it only reaches the publication pair.
const publicationActions = publications.config.publicationPairConfig.actions
const action = publicationActions.find((a) => a.id === 'file-list')

describe('Publications row menu order', () => {
	// CnIndexPage's show*Action props default to true; actionToggles only
	// switches them off with an explicit false.
	const toggles = publications.config.actionToggles
	const builtins = buildDefaultActions({
		flags: {
			view: toggles.showViewAction !== false,
			edit: toggles.showEditAction !== false,
			copy: toggles.showCopyAction !== false,
			del: toggles.showDeleteAction !== false,
		},
		viewIcon: {},
		handlers: {
			onView: () => {},
			onEdit: () => {},
			onCopy: () => {},
			onDelete: () => {},
		},
	})
	const { actions, warnings } = resolveRowActions(
		publicationActions,
		builtins,
		{
			prepare: (a) =>
				dispatchAction(a, { rowKey: 'id', customComponents: {} }),
		},
	)

	it('renders View, Edit, File list, Copy, Delete', () => {
		expect(actions.map((a) => a.label)).toEqual([
			'View',
			'Edit',
			'File list',
			'Copy',
			'Delete',
		])
		expect(warnings).toEqual([])
	})

	it('gives every entry the testid the e2e helpers click', () => {
		expect(actions.map(rowActionTestId)).toEqual([
			'cn-action-item-view',
			'cn-action-item-edit',
			'cn-action-item-file-list',
			'cn-action-item-copy',
			'cn-action-item-delete',
		])
	})
})

describe('Publications "File list" row action', () => {
	it('opens the clicked publication in the viewObject modal on its Files tab', () => {
		const calls = []
		const objectStore = {
			setActiveObject: (...args) => calls.push(['setActiveObject', ...args]),
		}
		const navigationStore = {
			setTransferData: (...args) => calls.push(['setTransferData', ...args]),
			setModal: (...args) => calls.push(['setModal', ...args]),
		}
		const row = { id: 'abc', '@self': { id: 'abc', register: '1', schema: '2' } }

		const dispatched = dispatchAction(action, {
			rowKey: 'id',
			customComponents: createPublicationActionHandlers({
				objectStore,
				navigationStore,
			}),
		})
		expect(typeof dispatched.handler).toBe('function')
		dispatched.handler(row)

		expect(calls).toEqual([
			['setActiveObject', 'publication', row],
			['setTransferData', { initialTab: 'files' }],
			['setModal', 'viewObject'],
		])
	})
})
