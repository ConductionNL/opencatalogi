/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the options the Dashboard hands its two area charts
 * (activity and publication usage): the same smooth gradient chart, the usage
 * chart with its two theme colours.
 */

import { flushPromises, mount } from '@vue/test-utils'
import Dashboard from './Dashboard.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: () => ({}),
	CnDashboardPage: {
		name: 'CnDashboardPage',
		template: '<div><slot name="widget-activity" /><slot name="widget-usage" /></div>',
	},
	CnChartWidget: {
		name: 'CnChartWidget',
		props: ['type', 'series', 'categories', 'height', 'options'],
		template: '<div class="chart" />',
	},
	CnStatsBlock: { name: 'CnStatsBlock', template: '<div />' },
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
jest.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button />' } }))
jest.mock('../../composables/useChartColors.js', () => ({
	useAccentChartColor: () => '#000',
	useCategoricalChartColors: () => ['#a', '#b', '#c'],
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { getCollection: () => ({ results: [] }) },
}))

// The page's script uses Nextcloud's global `t`.
globalThis.t = (app, text) => text
globalThis.n = (app, one, more, count) => (count === 1 ? one : more)

const BASE = {
	stroke: { curve: 'smooth', width: 2 },
	fill: {
		type: 'gradient',
		gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] },
	},
	xaxis: {
		labels: { rotate: -30, style: { fontSize: '11px' } },
		axisBorder: { show: false },
		axisTicks: { show: false },
	},
	yaxis: { labels: { style: { fontSize: '11px' } } },
	grid: { borderColor: 'var(--color-border, #e0e0e0)', strokeDashArray: 4 },
	dataLabels: { enabled: false },
	tooltip: { shared: true, intersect: false },
}

/**
 * Mount the dashboard with chart data for both charts.
 *
 * @return {Promise<Array<object>>} The two chart stubs, activity first.
 */
async function charts() {
	jest.spyOn(Dashboard.methods, 'loadDashboardData').mockImplementation(async () => {})
	const wrapper = mount(Dashboard, { global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	const vm = wrapper.vm.$.proxy
	vm.activityChartData = { labels: ['a'], series: [{ name: 's', data: [1] }] }
	vm.usageChartData = { labels: ['b'], series: [{ name: 'v', data: [2] }] }
	await flushPromises()
	const found = wrapper.findAllComponents({ name: 'CnChartWidget' })
	wrapper.unmount()
	return found
}

describe('Dashboard area chart options', () => {
	afterEach(() => jest.restoreAllMocks())

	it('gives the activity chart the smooth gradient options', async () => {
		const [activity] = await charts()

		expect(activity.props('options')).toEqual(BASE)
		expect(Object.keys(activity.props('options'))).toEqual(Object.keys(BASE))
		expect(activity.props('height')).toBe(280)
	})

	it('gives the usage chart the same options with its two colours second', async () => {
		const [, usage] = await charts()

		expect(usage.props('options')).toEqual({ ...BASE, colors: ['#a', '#b'] })
		expect(Object.keys(usage.props('options'))).toEqual(['stroke', 'colors', 'fill', 'xaxis', 'yaxis', 'grid', 'dataLabels', 'tooltip'])
		expect(usage.props('height')).toBe(240)
	})

	it('gives each chart its own options object', async () => {
		const [activity, usage] = await charts()

		expect(activity.props('options').fill).not.toBe(usage.props('options').fill)
	})
})
