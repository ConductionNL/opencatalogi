/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CaseTypeResyncDialog.vue`: it finds the case type the
 * entry names, lists what differs from the source with Keep or Take, and posts
 * only the taken properties.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import CaseTypeResyncDialog from './CaseTypeResyncDialog.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key) =>
		({ service_catalogue_register: '31', case_type_definition_schema: '1555' })[
			key
		],
}))

const definition = {
	id: 'def-1',
	identifier: 'ZT-EVENEMENT',
	title: 'Evenementenvergunning',
}
const diff = {
	sourceVersion: '2027',
	changes: [
		{
			property: 'duration',
			localValue: '8 weken',
			sourceValue: '10 weken',
			locallyChanged: false,
		},
		{
			property: 'description',
			localValue: 'Lokaal aangepast',
			sourceValue: 'Nieuw',
			locallyChanged: true,
		},
	],
	locallyChanged: ['description'],
}

/**
 * @param {object} properties The dialog properties.
 * @return {object} The wrapper.
 */
function open(properties) {
	navigationStore.setDialog('caseTypeResync', properties)
	return mount(CaseTypeResyncDialog)
}

describe('CaseTypeResyncDialog', () => {
	beforeEach(() => {
		jest.spyOn(console, 'log').mockImplementation(() => {})
		axios.get.mockImplementation((url) =>
			Promise.resolve({
				data: url.includes('/resync') ? diff : { results: [definition] },
			}),
		)
		axios.post.mockResolvedValue({ data: definition })
	})

	afterEach(() => {
		jest.clearAllMocks()
		jest.restoreAllMocks()
	})

	it('finds the case type by its identifier and lists what differs', async () => {
		const wrapper = open({
			caseType: 'ZT-EVENEMENT',
			entryTitle: 'Evenementenvergunning',
		})
		await flushPromises()

		const [lookupUrl, lookup] = axios.get.mock.calls[0]
		expect(lookupUrl).toMatch(/\/apps\/openregister\/api\/objects\/31\/1555$/)
		expect(lookup.params).toEqual({ identifier: 'ZT-EVENEMENT', _limit: 1 })
		expect(axios.get.mock.calls[1][0]).toMatch(
			/\/apps\/opencatalogi\/api\/case-types\/def-1\/resync$/,
		)
		expect(
			wrapper
				.findAll('tbody tr')
				.map((row) => row.attributes('data-property')),
		).toEqual(['duration', 'description'])
		expect(wrapper.vm.choices).toEqual({ duration: 'take', description: 'keep' })
	})

	it('posts only the taken properties', async () => {
		const wrapper = open({ caseType: 'ZT-EVENEMENT' })
		await flushPromises()

		await wrapper.vm.apply()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toMatch(/\/case-types\/def-1\/resync$/)
		expect(body).toEqual({ accepted: ['duration'] })
		expect(wrapper.vm.state).toBe('done')
	})

	it('says an entry without a case type has nothing to resync', async () => {
		const wrapper = open({ caseType: '', entryTitle: 'Afschrift' })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.vm.state).toBe('no-case-type')
		expect(wrapper.text()).toContain('starts no case type')
	})

	it("shows the server's reason when the source cannot be reached", async () => {
		axios.get.mockImplementation((url) =>
			url.includes('/resync')
				? Promise.reject({
						response: {
							data: {
								message: 'The national catalogue is unreachable.',
							},
						},
					})
				: Promise.resolve({ data: { results: [definition] } }),
		)
		const wrapper = open({ caseType: 'ZT-EVENEMENT' })
		await flushPromises()

		expect(wrapper.vm.state).toBe('error')
		expect(wrapper.text()).toContain('The national catalogue is unreachable.')
		expect(axios.post).not.toHaveBeenCalled()
	})
})
