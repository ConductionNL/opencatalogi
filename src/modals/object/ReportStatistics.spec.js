/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the Statistics lists of the merge report in
 * `MergeObject.vue` and the migration report in `MigrationObject.vue`: one
 * line per counter, in order, with 0 for a counter the result leaves out.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import MergeObject from './MergeObject.vue'
import MigrationObject from './MigrationObject.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

function t (app, text, vars = {}) {
  return text.replace(/{(\w+)}/g, (match, key) => vars[key] ?? match)
}

/**
 * Mount `component`, put it on `step` with `result` under `resultKey`, and
 * read the Statistics list.
 *
 * @param {object} component The modal.
 * @param {{step: number, resultKey: string, result: object}} report Where the report is.
 * @return {Promise<object>} The list's line texts and its raw markup.
 */
async function statistics(component, report) {
	const wrapper = mount(component, {
		global: {
			mocks: { t },
			// NcDialog teleports its content; render it in place instead.
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()
	// The component mixes <script setup> and options; its own proxy holds the
	// options state.
	const vm = wrapper.vm.$.proxy
	vm.step = report.step
	vm[report.resultKey] = report.result
	await flushPromises()
	const section = wrapper.findAll('.report-section').find((s) => s.find('h4').text() === 'Statistics')
	return {
		lines: section.findAll('li').map((li) => li.text()),
		html: section.find('ul').html(),
	}
}

describe('report statistics', () => {
	beforeEach(() => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('lists the merge counters in order', async () => {
		const { lines } = await statistics(MergeObject, {
			step: 3,
			resultKey: 'mergeResult',
			result: {
				success: true,
				statistics: {
					propertiesChanged: 1,
					filesTransferred: 2,
					filesDeleted: 3,
					relationsTransferred: 4,
					relationsDropped: 5,
					referencesUpdated: 6,
				},
			},
		})

		expect(lines).toEqual([
			'Properties changed: 1',
			'Files transferred: 2',
			'Files deleted: 3',
			'Relations transferred: 4',
			'Relations dropped: 5',
			'References updated: 6',
		])
	})

	it('shows 0 for merge counters the result leaves out', async () => {
		const { lines } = await statistics(MergeObject, {
			step: 3,
			resultKey: 'mergeResult',
			result: { success: true },
		})

		expect(lines).toEqual([
			'Properties changed: 0',
			'Files transferred: 0',
			'Files deleted: 0',
			'Relations transferred: 0',
			'Relations dropped: 0',
			'References updated: 0',
		])
	})

	it('lists the migration counters in order', async () => {
		const { lines } = await statistics(MigrationObject, {
			step: 4,
			resultKey: 'migrationResult',
			result: {
				success: true,
				statistics: { objectsMigrated: 7, objectsFailed: 8, propertiesMapped: 9, propertiesDiscarded: 10 },
			},
		})

		expect(lines).toEqual([
			'Objects migrated: 7',
			'Objects failed: 8',
			'Properties mapped: 9',
			'Properties discarded: 10',
		])
	})

	it('shows 0 for migration counters the result leaves out', async () => {
		const { lines } = await statistics(MigrationObject, {
			step: 4,
			resultKey: 'migrationResult',
			result: { success: false, statistics: { objectsFailed: 0 } },
		})

		expect(lines).toEqual([
			'Objects migrated: 0',
			'Objects failed: 0',
			'Properties mapped: 0',
			'Properties discarded: 0',
		])
	})

	it('renders plain list items without attributes', async () => {
		const { html } = await statistics(MergeObject, {
			step: 3,
			resultKey: 'mergeResult',
			result: { success: true },
		})

		expect(html.match(/<li>/g)).toHaveLength(6)
	})
})
