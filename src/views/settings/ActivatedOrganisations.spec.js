/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `ActivatedOrganisations.vue`: the section lists the activated
 * organisations, searches the TOOI registers, and activates or deactivates one;
 * a refusal leaves the switch as it was and names the organisation.
 */

import axios from '@nextcloud/axios'
import ActivatedOrganisations, { ACTIVATION_URL, SEARCH_URL } from './ActivatedOrganisations.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcNoteCard: {},
	NcTextField: {},
}))

const DORDRECHT = { uri: 'https://identifier.overheid.nl/tooi/id/gemeente/gm0505', label: 'gemeente Dordrecht', notation: 'gm0505', active: true, deprecated: false }
const ZWIJNDRECHT = { uri: 'https://identifier.overheid.nl/tooi/id/gemeente/gm0642', label: 'gemeente Zwijndrecht', notation: 'gm0642', active: false, deprecated: false }

/**
 * A fresh component state with its computed properties.
 *
 * @return {object} The state.
 */
function state() {
	const fresh = { ...ActivatedOrganisations.methods, ...ActivatedOrganisations.data() }
	for (const [name, getter] of Object.entries(ActivatedOrganisations.computed)) {
		Object.defineProperty(fresh, name, { get: getter.bind(fresh) })
	}
	return fresh
}

describe('ActivatedOrganisations', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it('lists the activated organisations without a query, and says when none is activated', async () => {
		const vm = state()
		axios.get.mockResolvedValueOnce({ data: { results: [], total: 0 } })
		await vm.search()

		expect(axios.get).toHaveBeenCalledWith(SEARCH_URL, { params: { search: '' } })
		expect(vm.results).toEqual([])
		expect(vm.emptyText).toBe('No organisation is activated yet, so every organisation is named in the sitemap.')
	})

	it('searches the registers by the trimmed query', async () => {
		const vm = state()
		vm.query = '  drecht '
		axios.get.mockResolvedValueOnce({ data: { results: [DORDRECHT, ZWIJNDRECHT], total: 2 } })
		await vm.search()

		expect(axios.get).toHaveBeenCalledWith(SEARCH_URL, { params: { search: 'drecht' } })
		expect(vm.results.map((row) => row.notation)).toEqual(['gm0505', 'gm0642'])

		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		vm.query = 'nergens'
		await vm.search()
		expect(vm.emptyText).toBe('No organisation in the TOOI register matches this search.')
	})

	it('activates and deactivates one organisation', async () => {
		const vm = state()
		vm.results = [DORDRECHT, ZWIJNDRECHT]
		axios.put.mockResolvedValue({ data: {} })

		await vm.setActive(ZWIJNDRECHT, true)
		expect(axios.put).toHaveBeenCalledWith(ACTIVATION_URL, { entry: ZWIJNDRECHT.uri, active: true })
		await vm.setActive(DORDRECHT, false)
		expect(axios.put).toHaveBeenCalledWith(ACTIVATION_URL, { entry: DORDRECHT.uri, active: false })

		expect(vm.results.map((row) => row.active)).toEqual([false, true])
		expect(vm.busy).toBe(false)
	})

	it('names the organisation when the activation is refused, and keeps its switch', async () => {
		const vm = state()
		vm.results = [ZWIJNDRECHT]
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'This organisation cannot be activated: ended' } } })

		await vm.setActive(ZWIJNDRECHT, true)

		expect(vm.error).toBe('gemeente Zwijndrecht: This organisation cannot be activated: ended')
		expect(vm.results[0].active).toBe(false)
	})

	it('shows why the registers could not be read', async () => {
		const vm = state()
		axios.get.mockRejectedValueOnce(new Error('Network Error'))
		await vm.search()

		expect(vm.error).toBe('Network Error')
		expect(vm.busy).toBe(false)
	})
})
