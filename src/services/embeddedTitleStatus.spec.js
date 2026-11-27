/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "The officer sees what was written" (REQ-PFF-002): each published file's
 * stored outcome reads as a sentence, written or not written with the reason.
 *
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.3
 */

import WooBatchDetail from '../views/woo/WooBatchDetail.vue'
import { embeddedTitleLabel, embeddedTitleRows } from './embeddedTitleStatus.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/initial-state', () => ({ loadState: () => '' }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../dialogs/woo/WooAssessmentDialog.vue', () => ({}))
jest.mock('../store/store.js', () => ({ objectStore: { registerObjectType: jest.fn() } }))

describe('embeddedTitleLabel', () => {
	it('says the title was written', () => {
		expect(embeddedTitleLabel({ status: 'written', hashBefore: 'a', hashAfter: 'b' })).toBe('Title written into the file')
	})

	it('names the reason a title was not written', () => {
		expect(embeddedTitleLabel({ status: 'skipped', reason: 'signed' })).toBe('Title not written: the file is signed')
		expect(embeddedTitleLabel({ status: 'skipped', reason: 'verifier', verdict: 'leaking' })).toBe('Title not written: the redaction check did not pass')
		expect(embeddedTitleLabel({ status: 'skipped', reason: 'encrypted' })).toBe('Title not written: the file is encrypted')
		expect(embeddedTitleLabel({ status: 'skipped', reason: 'unparseable' })).toBe('Title not written: the file could not be read')
		expect(embeddedTitleLabel({ status: 'skipped', reason: 'unsupported-type' })).toBe('Title not written: this file type has no title')
	})

	it('falls back to a plain not-written for any other outcome', () => {
		expect(embeddedTitleLabel({ status: 'failed', reason: 'write-error' })).toBe('Title not written')
		expect(embeddedTitleLabel({ status: 'skipped' })).toBe('Title not written')
		expect(embeddedTitleLabel(null)).toBe('Title not written')
	})
})

describe('embeddedTitleRows', () => {
	it('lists one row per file, by name', () => {
		const rows = embeddedTitleRows({
			embeddedTitles: {
				'signed.pdf': { status: 'skipped', reason: 'signed' },
				'besluit.pdf': { status: 'written' },
			},
		})
		expect(rows).toEqual([
			{ name: 'besluit.pdf', label: 'Title written into the file', written: true },
			{ name: 'signed.pdf', label: 'Title not written: the file is signed', written: false },
		])
	})

	it('lists nothing without stored outcomes', () => {
		expect(embeddedTitleRows({})).toEqual([])
		expect(embeddedTitleRows({ embeddedTitles: [] })).toEqual([])
		expect(embeddedTitleRows(null)).toEqual([])
	})

	it('is what the batch page shows', () => {
		const batch = { wooPublication: { embeddedTitles: { 'besluit.pdf': { status: 'written' } } } }
		expect(WooBatchDetail.computed.embeddedTitles.call({ batch })).toEqual([
			{ name: 'besluit.pdf', label: 'Title written into the file', written: true },
		])
		expect(WooBatchDetail.computed.embeddedTitles.call({ batch: null })).toEqual([])
	})
})
