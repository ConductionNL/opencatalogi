/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `NationalAnnounceWidget.vue`: who counts as an admin,
 * how channels and gazettes are named, what decision is sent, and how a
 * delivery answer becomes the result rows.
 */

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import NationalAnnounceWidget from './NationalAnnounceWidget.vue'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const T = (app, text) => text

/**
 * Mount the widget for publication pub-1.
 *
 * @return {object} The view model.
 */
function vm() {
	return mount(NationalAnnounceWidget, {
		props: { objectId: 'pub-1' },
		global: {
			mocks: { t: T },
			stubs: { NcSelect: true, NcTextField: true, NcButton: true, NcNoteCard: true, NcLoadingIcon: true },
		},
	}).vm
}

beforeEach(() => {
	getCurrentUser.mockReset()
	getCurrentUser.mockReturnValue({ uid: 'admin', isAdmin: true })
})

describe('NationalAnnounceWidget', () => {
	it('counts only a signed-in admin as admin', () => {
		expect(vm().isAdmin).toBe(true)

		getCurrentUser.mockReturnValue({ uid: 'u', isAdmin: false })
		expect(vm().isAdmin).toBe(false)

		getCurrentUser.mockReturnValue(null)
		expect(vm().isAdmin).toBe(false)
	})

	it('offers the four gazettes in order', () => {
		expect(vm().publicationTypeOptions.map((option) => option.value)).toEqual([
			'gemeenteblad',
			'provincieblad',
			'waterschapsblad',
			'staatscourant',
		])
	})

	it('names the known channels and passes any other through', () => {
		const widget = vm()
		expect(widget.channelLabel('national-publication-platform')).toBe('National publication platform')
		expect(widget.channelLabel('local-channel')).toBe('Own channel')
		expect(widget.channelLabel('rss')).toBe('rss')
	})

	it('sends the publication with this id, the page url, the type and the effective date', () => {
		const widget = vm()
		widget.publicationType = { value: 'staatscourant' }
		widget.effectiveDate = '2026-12-01'

		const decision = widget.announcementDecision({ id: 'stored', title: 'Besluit' })

		expect(decision).toEqual({
			id: 'pub-1',
			title: 'Besluit',
			url: window.location.href,
			publicationType: 'staatscourant',
			effectiveDate: '2026-12-01',
		})
	})

	it('sends an empty type when none was chosen', () => {
		expect(vm().announcementDecision({}).publicationType).toBe('')
	})

	it('lists delivered channels first, then unreachable ones with their reason', () => {
		const rows = vm().toResults({
			delivered: [{ channel: 'a', delivery: { identifier: 'id-1' } }, { channel: 'b' }],
			unreachable: [{ channel: 'c', reason: 'down' }],
		})

		expect(rows).toEqual([
			{ channel: 'a', delivered: true, identifier: 'id-1', reason: '' },
			{ channel: 'b', delivered: true, identifier: '', reason: '' },
			{ channel: 'c', delivered: false, identifier: '', reason: 'down' },
		])
	})

	it('lists no rows for an answer without channels', () => {
		expect(vm().toResults({})).toEqual([])
	})
	it('prefills the effective date with the stored validFrom', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: 'pub-1', validFrom: '2027-01-01' } })
		const widget = vm()
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/{register}/{schema}/{id}')
		expect(widget.effectiveDate).toBe('2027-01-01')
	})

	it('keeps a date the editor typed and leaves the field empty without a validFrom', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: 'pub-1' } })
		const empty = vm()
		await flushPromises()
		expect(empty.effectiveDate).toBe('')

		let answer
		axios.get.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
		const typed = vm()
		typed.effectiveDate = '2026-12-01'
		answer({ data: { validFrom: '2027-01-01' } })
		await flushPromises()
		expect(typed.effectiveDate).toBe('2026-12-01')
	})
})
