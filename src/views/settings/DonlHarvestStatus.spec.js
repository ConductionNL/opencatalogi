/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the data.overheid.nl status block: "Harvested" only on evidence,
 * and the operator's save posts the settings with the status.
 */

import axios from '@nextcloud/axios'
import DonlHarvestStatus from './DonlHarvestStatus.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => String(vars[key]))

/**
 * A component state over a status.
 *
 * @param {object} status The status.
 * @return {object} The state.
 */
function state(status = {}) {
	const self = { ...DonlHarvestStatus.methods, t, ...DonlHarvestStatus.data(), error: '', busy: false }
	self.status = { ...self.status, ...status }
	return self
}

const computed = (name, status) => DonlHarvestStatus.computed[name].call(state(status))

describe('DonlHarvestStatus', () => {
	it('says registered, last harvested and the count of the scenario', () => {
		const status = {
			status: 'registered',
			registeredAt: '2026-10-14T09:00:00+00:00',
			lastFetchAt: '2026-11-13T02:00:00+00:00',
			donlDatasetCount: 118,
			feedDatasetCount: 120,
			donlCountAt: '2026-11-14T03:00:00+00:00',
			harvested: true,
		}
		expect(computed('statusLabel', status)).toBe('Registered on 2026-10-14.')
		expect(computed('harvestLabel', status)).toBe('Harvested. Last harvested on 2026-11-13.')
		expect(computed('countLabel', status)).toBe('118 of 120 datasets on data.overheid.nl, checked on 2026-11-14.')
	})

	it('does not say harvested on the operator word alone', () => {
		const status = { status: 'registered', registeredAt: '2026-10-14', donlDatasetCount: 0, harvested: false }
		expect(computed('harvestLabel', status)).toBe('Not harvested yet: no harvester visit and no datasets on data.overheid.nl.')
	})

	it('shows no count before the first check', () => {
		expect(computed('countLabel', {})).toBe('')
		expect(computed('statusLabel', {})).toBe('Registration not requested yet.')
	})

	it('posts the settings with the status and takes the answer', async () => {
		axios.post.mockResolvedValue({ data: { status: 'requested', requestedAt: '2026-11-14T10:00:00+00:00', source: 'S' } })
		const self = state()
		self.form = { apiUrl: 'https://ckan.example/api/3', source: 'S', harvesterPattern: '' }

		await DonlHarvestStatus.methods.save.call(self, 'requested')

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/dcat/donl-registration', {
			apiUrl: 'https://ckan.example/api/3', source: 'S', harvesterPattern: '', status: 'requested',
		})
		expect(self.status.status).toBe('requested')
		expect(self.form.source).toBe('S')
		expect(self.busy).toBe(false)
	})

	it('shows the refusal the server gives', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'Refused' } } })
		const self = state()

		await DonlHarvestStatus.methods.save.call(self, 'registered')

		expect(self.error).toBe('Refused')
	})
})
