/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Products and services page's Resync row action.
 */

import manifestFragment from '../manifest.d/service-catalogue-admin-screens.json'
import { createCaseTypeActionHandlers } from './caseTypeActions.js'

describe('openCaseTypeResync', () => {
	it('opens the resync dialog for the case type the entry starts', () => {
		const navigationStore = { setDialog: jest.fn() }
		const { openCaseTypeResync } = createCaseTypeActionHandlers({
			navigationStore,
		})

		openCaseTypeResync({
			item: {
				title: 'Evenementenvergunning',
				formBinding: { caseType: 'ZT-EVENEMENT', formName: 'Evenement' },
			},
		})

		expect(navigationStore.setDialog).toHaveBeenCalledWith('caseTypeResync', {
			caseType: 'ZT-EVENEMENT',
			entryTitle: 'Evenementenvergunning',
		})
	})

	it('opens it without a case type for an entry that starts none', () => {
		const navigationStore = { setDialog: jest.fn() }
		createCaseTypeActionHandlers({ navigationStore }).openCaseTypeResync({
			item: { title: 'Afschrift' },
		})

		expect(navigationStore.setDialog).toHaveBeenCalledWith('caseTypeResync', {
			caseType: '',
			entryTitle: 'Afschrift',
		})
	})

	it('is the handler the page names', () => {
		const page = manifestFragment.pages.find((p) => p.id === 'ServiceCatalogue')
		const resync = page.config.actions.find((a) => a.id === 'resync')

		expect(resync.handler).toBe('openCaseTypeResync')
		expect(
			Object.keys(createCaseTypeActionHandlers({ navigationStore: {} })),
		).toContain(resync.handler)
	})
})
