/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `WooAnnualReportWidget.vue`: Generate annual report is
 * offered only when OpenRegister can generate it, and calls its generator with
 * the shipped template (REQ-WAR-002).
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import WooAnnualReportWidget from './WooAnnualReportWidget.vue'
import {
	filenameFrom,
	generateAnnualReport,
	reportYears,
} from '../../services/wooAnnualReport.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/{(\w+)}/g, (match, key) => vars?.[key] ?? match),
}))

/**
 * @return {Promise<object>} the wrapper.
 */
async function mountWidget() {
	const wrapper = mount(WooAnnualReportWidget, {
		global: {
			stubs: {
				NcLoadingIcon: true,
				NcSelect: true,
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: {
					template: '<button @click="$emit(\'click\')"><slot /></button>',
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('WooAnnualReportWidget', () => {
	it('has no action and says why when OpenRegister cannot generate the report', async () => {
		axios.get.mockResolvedValue({
			data: {
				available: false,
				templateId: null,
				reason: 'needs-newer-openregister',
				formats: [],
			},
		})
		const wrapper = await mountWidget()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/annual-report',
		)
		expect(
			wrapper.find('[data-testid="woo-annual-report-generate"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="woo-annual-report-unavailable"]').text(),
		).toBe('The Woo annual report needs a newer OpenRegister.')
	})

	it('has no action when the availability cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = await mountWidget()

		expect(
			wrapper.find('[data-testid="woo-annual-report-generate"]').exists(),
		).toBe(false)
	})

	it('generates the chosen year from the shipped template', async () => {
		axios.get.mockResolvedValue({
			data: {
				available: true,
				templateId: 't-1',
				reason: null,
				formats: ['pdf', 'ods', 'csv'],
			},
		})
		axios.post.mockResolvedValue({
			data: new Blob(['%PDF']),
			headers: {
				'content-disposition':
					'attachment; filename="woo-jaarverslag-2026.pdf"',
			},
		})
		global.URL.createObjectURL = jest.fn(() => 'blob:1')
		global.URL.revokeObjectURL = jest.fn()
		const click = jest
			.spyOn(HTMLAnchorElement.prototype, 'click')
			.mockImplementation(() => {})
		const wrapper = await mountWidget()

		await wrapper
			.find('[data-testid="woo-annual-report-generate"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/reports/generate',
			{
				templateId: 't-1',
				year: String(new Date().getFullYear()),
				format: 'pdf',
			},
			{ responseType: 'blob' },
		)
		expect(click).toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="woo-annual-report-failed"]').exists(),
		).toBe(false)
	})

	it('says so when the generation fails', async () => {
		axios.get.mockResolvedValue({
			data: {
				available: true,
				templateId: 't-1',
				reason: null,
				formats: ['pdf'],
			},
		})
		axios.post.mockRejectedValue(new Error('403'))
		const wrapper = await mountWidget()

		await wrapper
			.find('[data-testid="woo-annual-report-generate"]')
			.trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="woo-annual-report-failed"]').exists(),
		).toBe(true)
	})
})

describe('wooAnnualReport service', () => {
	it('offers this year and the four before it', () => {
		expect(reportYears(new Date('2026-11-25'))).toEqual([
			2026, 2025, 2024, 2023, 2022,
		])
	})

	it('reads the file name from the download header', () => {
		expect(filenameFrom('attachment; filename="woo-jaarverslag_2026.pdf"')).toBe(
			'woo-jaarverslag_2026.pdf',
		)
		expect(filenameFrom(undefined)).toBe('')
	})

	it('names the file after the year when OpenRegister sends no name', async () => {
		axios.post.mockResolvedValue({ data: new Blob(['x']), headers: {} })
		const save = jest.fn()

		const name = await generateAnnualReport(
			{ templateId: 't-1', year: 2025, format: 'csv' },
			save,
		)

		expect(name).toBe('woo-jaarverslag-2025.csv')
		expect(save).toHaveBeenCalledWith(
			expect.any(Blob),
			'woo-jaarverslag-2025.csv',
		)
	})
})
