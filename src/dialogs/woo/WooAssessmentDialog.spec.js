/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `WooAssessmentDialog.vue` (REQ-WDA-002): the grounds come from
 * the catalogue, the current ones are preselected, and nothing is handed back
 * without at least one ground.
 */

import axios from '@nextcloud/axios'
import WooAssessmentDialog, { groundOption } from './WooAssessmentDialog.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

/**
 * A dialog state with its methods bound.
 *
 * @param {object} props The props.
 * @return {object} The state.
 */
function dialog(props = {}) {
	const emitted = []
	return {
		...WooAssessmentDialog.data(),
		...WooAssessmentDialog.methods,
		target: 'deels_openbaar',
		current: [],
		...props,
		emitted,
		$emit: (name, payload) => emitted.push([name, payload]),
	}
}

describe('WooAssessmentDialog', () => {
	it('reads a catalogue row as code and label', () => {
		expect(
			groundOption({
				article: '5.1.2.e',
				description: 'Eerbiediging persoonlijke levenssfeer',
			}),
		).toEqual({
			code: '5.1.2.e',
			label: '5.1.2.e Eerbiediging persoonlijke levenssfeer',
		})
		expect(groundOption({})).toEqual({ code: '', label: '' })
	})

	it('loads the grounds and preselects the current ones', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ article: '5.1.2.e', description: 'Privacy' },
					{
						article: '5.2',
						description: 'Persoonlijke beleidsopvattingen',
					},
				],
			},
		})
		const state = dialog({ current: ['5.2'] })

		await state.loadGrounds()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/weigeringsgronden',
		)
		expect(state.grounds.map((g) => g.code)).toEqual(['5.1.2.e', '5.2'])
		expect(state.selected.map((g) => g.code)).toEqual(['5.2'])
		expect(state.loading).toBe(false)
	})

	it('hands back nothing without a ground, and the codes with one', () => {
		const state = dialog()

		state.confirm()
		expect(state.emitted).toEqual([])

		state.selected = [{ code: '5.1.2.e', label: '' }]
		state.confirm()
		expect(state.emitted).toEqual([['confirm', ['5.1.2.e']]])
	})

	it('closes without saving when the dialog closes itself', () => {
		const state = dialog()

		state.onOpenChange(false)

		expect(state.emitted).toEqual([['close', undefined]])
	})
})
