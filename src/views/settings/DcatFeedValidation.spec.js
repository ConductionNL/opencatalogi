/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for Validate DCAT feed in the admin DCAT settings: only DCAT
 * catalogues are offered, both reports are fetched and kept per catalogue,
 * and every violation reads as a sentence naming what is wrong.
 */

import axios from '@nextcloud/axios'
import DcatFeedValidation from './DcatFeedValidation.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => String(vars[key]))

/**
 * A fresh component state.
 *
 * @return {object} The state.
 */
function state() {
	return { ...DcatFeedValidation.methods, t, ...DcatFeedValidation.data() }
}

describe('DcatFeedValidation', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('offers only the catalogues that publish a DCAT feed', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ slug: 'woo', title: 'Woo', hasDcat: true },
					{ slug: 'intern', title: 'Intern', hasDcat: false },
					{ slug: 'open', hasDcat: true },
				],
			},
		})
		const self = state()

		await self.load()

		expect(self.catalogs).toEqual([{ slug: 'woo', title: 'Woo' }, { slug: 'open', title: '' }])
		expect(self.loaded).toBe(true)
		expect(self.error).toBe('')
	})

	it('says so when the catalogues cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const self = state()

		await self.load()

		expect(self.catalogs).toEqual([])
		expect(self.error).toBe('The catalogues could not be loaded.')
	})

	it('fetches both reports for the catalogue and keeps them under its slug', async () => {
		const dcat = { valid: false, checked: ['dct:title'], violations: [{ iri: 'https://h/d/1', missing: ['dct:title'] }] }
		const donl = { valid: true, violations: [], sourceUrl: 'https://h/apps/opencatalogi/api/dcat' }
		axios.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/donl-validate') ? donl : dcat }))
		const self = state()

		await self.validate('woo')

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/catalogs/woo/dcat/validate')
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/catalogs/woo/dcat/donl-validate')
		expect(self.results.woo).toEqual({ dcat, donl })
		expect(self.busy.woo).toBe(false)
	})

	it('keeps the server refusal for that catalogue alone', async () => {
		axios.get.mockRejectedValue({ response: { data: { error: 'Catalog not found' } } })
		const self = state()
		self.results = { other: { dcat: {}, donl: {} } }

		await self.validate('gone')

		expect(self.results.gone).toEqual({ error: 'Catalog not found' })
		expect(self.results.other).toEqual({ dcat: {}, donl: {} })
	})

	it('names the profile the verdict was measured against', () => {
		expect(state().profileLabel({ profile: { declared: '3.0' } })).toBe('Checked against DCAT-AP-NL 3.0.')
		expect(state().profileLabel({})).toBe('')
	})

	it('reads each kind of violation as a sentence', () => {
		const self = state()

		expect(self.violationText({ iri: 'https://h/d/1', missing: ['dct:publisher', 'dcat:landingPage'] }))
			.toBe('https://h/d/1: missing dct:publisher, dcat:landingPage')
		expect(self.violationText({ iri: 'https://h/d/2', axis: 'dcat:theme', reason: 'x' }))
			.toBe('https://h/d/2: the theme is not a data-theme from the controlled list.')
		expect(self.violationText({ axis: 'profile-version', declared: '2.0', published: '3.0' }))
			.toBe('The feed declares DCAT-AP-NL 2.0, while 3.0 is the published version.')
	})
})
