/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Retention review page's handlers: a row or a selection opens the
 * decision dialog with the decision its action names.
 */

import manifestFragment from '../manifest.d/retention-review-queue.json'
import { createRetentionActionHandlers } from './retentionActions.js'

/**
 * @return {{handlers: object, navigationStore: object, assign: Function}} Handlers over doubles.
 */
function setup() {
	const navigationStore = { setDialog: jest.fn() }
	const assign = jest.fn()
	const handlers = createRetentionActionHandlers({
		navigationStore,
		generateUrl: (path) => `/index.php${path}`,
		assign,
	})
	return { handlers, navigationStore, assign }
}

describe('Retention review handlers', () => {
	it('opens the decision dialog on the clicked row', () => {
		const { handlers, navigationStore } = setup()
		const item = { id: 'p1', title: 'Convenant leerlingenvervoer' }

		handlers.openRetentionDecision({ actionId: 'extend', item })

		expect(navigationStore.setDialog).toHaveBeenCalledWith('retentionDecision', {
			decision: 'extend',
			ids: ['p1'],
			publications: [item],
		})
	})

	it('opens it on the selection, by id', () => {
		const { handlers, navigationStore } = setup()

		handlers.openRetentionDecision({
			actionId: 'archive',
			selectedIds: ['p1', 'p2'],
			count: 2,
		})

		expect(navigationStore.setDialog).toHaveBeenCalledWith('retentionDecision', {
			decision: 'archive',
			ids: ['p1', 'p2'],
			publications: [],
		})
	})

	it('downloads the retention report', () => {
		const { handlers, assign } = setup()

		handlers.downloadRetentionReport()

		expect(assign).toHaveBeenCalledWith(
			'/index.php/apps/opencatalogi/api/retention/report',
		)
	})

	it('names only these handlers and only the three decisions on the page', () => {
		const { handlers } = setup()
		const page = manifestFragment.pages.find((p) => p.id === 'RetentionReview')
		const declared = [
			...page.config.actions,
			...page.config.bulkActions,
			...page.config.headerActions,
		]

		for (const action of declared) {
			expect(Object.keys(handlers)).toContain(action.handler)
		}
		expect(
			[...page.config.actions, ...page.config.bulkActions].map((a) => a.id),
		).toEqual([
			'extend',
			'depublish',
			'archive',
			'extend',
			'depublish',
			'archive',
		])
	})
})
