/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications index's bulk handlers: what each stages and opens for a
 * selection, and what it refuses.
 */

import { createPublicationActionHandlers } from './publicationActions.js'

/**
 * Handlers over recording store doubles.
 *
 * @return {{handlers: object, objectStore: object, navigationStore: object, showError: Function}} The handlers and their doubles.
 */
function setup() {
	const objectStore = {
		setSelectedObjects: jest.fn(),
		setActiveObject: jest.fn(),
	}
	const navigationStore = {
		setDialog: jest.fn(),
		setModal: jest.fn(),
		setTransferData: jest.fn(),
	}
	const showError = jest.fn()
	const handlers = createPublicationActionHandlers({
		objectStore,
		navigationStore,
		showError,
		t: (app, text) => text,
	})
	return { handlers, objectStore, navigationStore, showError }
}

const row = (id) => ({ id, '@self': { id, register: 19, schema: 173 } })

describe('openSelectionDialog', () => {
	it('stages the selected rows and opens the dialog the action names', () => {
		const { handlers, objectStore, navigationStore } = setup()
		const objects = [row('a'), row('b'), row('c')]

		const opened = handlers.openSelectionDialog({
			objects,
			props: { dialog: 'massLockObjects' },
		})

		expect(opened).toBe(true)
		expect(objectStore.setSelectedObjects).toHaveBeenCalledWith(objects)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('massLockObjects')
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('opens a modal when the action names one', () => {
		const { handlers, navigationStore } = setup()

		handlers.openSelectionDialog({
			objects: [row('a')],
			props: { modal: 'migrationObject' },
		})

		expect(navigationStore.setModal).toHaveBeenCalledWith('migrationObject')
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('opens nothing without rows or without a dialog name', () => {
		const { handlers, objectStore, navigationStore } = setup()

		expect(
			handlers.openSelectionDialog({
				objects: [],
				props: { dialog: 'massPublishObjects' },
			}),
		).toBe(false)
		expect(handlers.openSelectionDialog({ objects: [row('a')] })).toBe(false)
		expect(objectStore.setSelectedObjects).not.toHaveBeenCalled()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})
})

describe('openPublicationMerge', () => {
	it('opens the merge dialog at step 2, first selected as source', () => {
		const { handlers, navigationStore } = setup()
		const [source, target] = [row('a'), row('b')]

		expect(handlers.openPublicationMerge({ objects: [source, target] })).toBe(
			true,
		)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({
			mergeSource: source,
			mergeTarget: target,
			startStep: 2,
		})
		expect(navigationStore.setModal).toHaveBeenCalledWith('mergeObject')
	})

	it.each([[1], [3]])('refuses %i selected and says why', (count) => {
		const { handlers, navigationStore, showError } = setup()
		const objects = Array.from({ length: count }, (_, i) => row(`r${i}`))

		expect(handlers.openPublicationMerge({ objects })).toBe(false)
		expect(showError).toHaveBeenCalledWith(
			'Select exactly two publications to merge',
		)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})
})

describe('openPublicationMigration', () => {
	it('opens the migration dialog with the selected rows', () => {
		const { handlers, objectStore, navigationStore } = setup()
		const objects = [row('a'), row('b'), row('c')]

		expect(handlers.openPublicationMigration({ objects })).toBe(true)
		expect(objectStore.setSelectedObjects).toHaveBeenCalledWith(objects)
		expect(navigationStore.setModal).toHaveBeenCalledWith('migrationObject')
	})
})
