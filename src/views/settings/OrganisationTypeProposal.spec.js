/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `OrganisationTypeProposal.vue`: choosing a type shows its
 * proposal checked, the administrator unchecks what they do publish, and only
 * the checked categories are written as hidden; a refused one is listed.
 */

import axios from '@nextcloud/axios'
import OrganisationTypeProposal, {
	labelsByUri,
	SCHEME,
	TYPE_URL,
	typeLabels,
} from './OrganisationTypeProposal.vue'
import { CHOICES_URL } from './valueListOrder.js'

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
	NcSelect: {},
}))

const KERN = 'https://identifier.overheid.nl/tooi/def/thes/kern/'
const STATES_GENERAL = {
	category: 'infocat007',
	tooiUri: KERN + 'c_c76862ab',
	article: 'Wet open overheid, artikel 3.3',
	basis: 'vergaderstukken en verslagen van de Kamers',
}
const INCOMING = {
	category: 'infocat006',
	tooiUri: KERN + 'c_8c840238',
	article: 'Wet open overheid, artikel 3.3',
	basis: 'bij de Kamers ingekomen stukken',
}

/**
 * A fresh component state with its computed properties.
 *
 * @return {object} The state.
 */
function state() {
	const fresh = {
		...OrganisationTypeProposal.methods,
		...OrganisationTypeProposal.data(),
		$emit: jest.fn(),
	}
	for (const [name, getter] of Object.entries(OrganisationTypeProposal.computed)) {
		Object.defineProperty(fresh, name, { get: getter.bind(fresh) })
	}
	return fresh
}

describe('OrganisationTypeProposal', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it('loads the type, its proposal and the category labels', async () => {
		axios.get.mockImplementation((url) => Promise.resolve(
			url === TYPE_URL
				? { data: { type: 'waterschap', types: ['gemeente', 'waterschap'], proposal: [STATES_GENERAL] } }
				: { data: { entries: [{ uri: STATES_GENERAL.tooiUri, label: 'Vergaderstukken Staten-Generaal' }] } },
		))
		const s = state()
		await s.load()
		expect(axios.get).toHaveBeenCalledWith(CHOICES_URL, { params: { scheme: SCHEME } })
		expect(s.selectedOption).toEqual({ id: 'waterschap', label: 'Water board' })
		expect(s.typeOptions.map((option) => option.id)).toEqual(['gemeente', 'waterschap'])
		expect(s.checked).toEqual([STATES_GENERAL.tooiUri])
		expect(s.labelOf(STATES_GENERAL)).toBe('Vergaderstukken Staten-Generaal')
	})

	it('choosing a type stores it and checks its proposal, hiding nothing', async () => {
		axios.put.mockResolvedValue({
			data: { type: 'zelfstandig-bestuursorgaan', types: [], proposal: [STATES_GENERAL, INCOMING] },
		})
		const s = state()
		await s.choose({ id: 'zelfstandig-bestuursorgaan' })
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith(TYPE_URL, { type: 'zelfstandig-bestuursorgaan' })
		expect(s.checked).toEqual([STATES_GENERAL.tooiUri, INCOMING.tooiUri])
		expect(s.busy).toBe(false)
	})

	it('hides only the checked categories and lists a refusal', async () => {
		const s = state()
		s.apply({ type: 'gemeenschappelijke-regeling', types: [], proposal: [STATES_GENERAL, INCOMING] })
		s.labels = { [INCOMING.tooiUri]: 'Ingekomen stukken' }
		s.toggle(STATES_GENERAL.tooiUri, false)
		s.toggle(STATES_GENERAL.tooiUri, true)
		s.toggle(STATES_GENERAL.tooiUri, false)
		axios.put.mockRejectedValue({ response: { data: { error: 'Move the 3 publications filed under this category before hiding it.' } } })

		await s.hideChecked()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith(CHOICES_URL, {
			scheme: SCHEME,
			entry: INCOMING.tooiUri,
			choice: { hidden: true },
		})
		expect(s.errors).toEqual(['Ingekomen stukken: Move the 3 publications filed under this category before hiding it.'])
		expect(s.done).toBe(false)
		expect(s.$emit).toHaveBeenCalledWith('hidden')
	})

	it('reports success when every checked category is hidden', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const s = state()
		s.apply({ type: 'waterschap', types: [], proposal: [STATES_GENERAL] })
		await s.hideChecked()
		expect(s.done).toBe(true)
		expect(s.errors).toEqual([])
	})

	it('shows a failed load or a refused type', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const s = state()
		await s.load()
		expect(s.errors).toEqual(['offline'])

		axios.put.mockRejectedValue({ response: { data: { error: 'Choose one of the listed organisation types.' } } })
		await s.choose({ id: 'nope' })
		expect(s.errors).toEqual(['Choose one of the listed organisation types.'])
		await s.choose(null)
		expect(axios.put).toHaveBeenCalledTimes(1)
	})

	it('labels every type and every entry', () => {
		expect(Object.keys(typeLabels())).toEqual([
			'gemeente', 'provincie', 'waterschap', 'gemeenschappelijke-regeling',
			'rijksoverheid', 'zelfstandig-bestuursorgaan', 'overig',
		])
		expect(labelsByUri([{ uri: 'u', label: 'L' }])).toEqual({ u: 'L' })
		const s = state()
		expect(s.labelOf(STATES_GENERAL)).toBe('infocat007')
	})
})
