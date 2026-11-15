/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the facet picker (`FacetComponent.vue`): which facets are
 * active, the defaults a facet is switched on with, changing its type and
 * date interval, removing and clearing facets, and the option labels.
 * The search store is the boundary and is replaced by a plain double.
 */

import { mount } from '@vue/test-utils'
import FacetComponent from './FacetComponent.vue'

const mockStore = {
	activeFacets: {},
	toggleActiveFacet: jest.fn(),
	clearAllActiveFacets: jest.fn(),
	get getActiveFacets() {
		return this.activeFacets
	},
	get hasActiveFacets() {
		return Object.keys(this.activeFacets).length > 0
	},
	hasFacetableFields: false,
	isFacetsLoading: false,
	availableMetadataFacets: {},
	availableObjectFieldFacets: {},
}

jest.mock('../store/modules/search.ts', () => ({ useSearchStore: () => mockStore }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))

/**
 * Mount the component and hand back its setup bindings.
 *
 * @return {object} The component instance.
 */
function facets() {
	return mount(FacetComponent).vm
}

beforeEach(() => {
	mockStore.activeFacets = {}
	mockStore.toggleActiveFacet.mockReset()
	mockStore.clearAllActiveFacets.mockReset()
})

describe('FacetComponent', () => {
	it('reads which facets are active, with their type and interval', () => {
		mockStore.activeFacets = {
			published: { type: 'date_histogram', config: { interval: 'year' } },
			category: { type: 'terms', config: {} },
		}
		const vm = facets()

		expect(vm.isActiveFacet('published')).toBe(true)
		expect(vm.isActiveFacet('toString')).toBe(false)
		expect(vm.getActiveFacetType('category')).toBe('terms')
		expect(vm.getActiveFacetType('missing')).toBeNull()
		expect(vm.getActiveFacetInterval('published')).toBe('year')
		expect(vm.getActiveFacetInterval('category')).toBe('month')
		expect(vm.getActiveFacetInterval('missing')).toBe('month')
	})

	it('switches a facet on with its first type, and a date histogram with its first interval', () => {
		const vm = facets()

		vm.toggleFacet('category', { facet_types: ['terms', 'range'] }, true)
		vm.toggleFacet('published', { facet_types: ['date_histogram'], intervals: ['week', 'month'] }, true)
		vm.toggleFacet('modified', { facet_types: ['date_histogram'] }, true)
		vm.toggleFacet('plain', {}, true)

		expect(mockStore.toggleActiveFacet.mock.calls).toEqual([
			['category', 'terms', true, {}],
			['published', 'date_histogram', true, { interval: 'week' }],
			['modified', 'date_histogram', true, { interval: 'month' }],
			['plain', 'terms', true, {}],
		])
	})

	it('switches a facet off, removes one, and clears them all', () => {
		const vm = facets()

		vm.toggleFacet('category', { facet_types: ['terms'] }, false)
		vm.removeFacet('published')
		vm.clearAllFacets()

		expect(mockStore.toggleActiveFacet.mock.calls).toEqual([
			['category', '', false],
			['published', '', false],
		])
		expect(mockStore.clearAllActiveFacets).toHaveBeenCalledTimes(1)
	})

	it('changes a facet\'s type, giving a date histogram its first interval', () => {
		const vm = facets()

		vm.updateFacetType('published', 'date_histogram', { intervals: ['day'] })
		vm.updateFacetType('category', 'range', { intervals: ['day'] })

		expect(mockStore.toggleActiveFacet.mock.calls).toEqual([
			['published', 'date_histogram', true, { interval: 'day' }],
			['category', 'range', true, {}],
		])
	})

	it('changes the interval of an active facet only, keeping its other settings', () => {
		mockStore.activeFacets = { published: { type: 'date_histogram', config: { interval: 'month', size: 5 } } }
		const vm = facets()

		vm.updateFacetInterval('published', 'week')
		vm.updateFacetInterval('missing', 'week')

		expect(mockStore.toggleActiveFacet.mock.calls).toEqual([
			['published', 'date_histogram', true, { interval: 'week', size: 5 }],
		])
	})

	it('labels facet types and intervals, defaulting the intervals', () => {
		const vm = facets()

		expect(vm.getFacetTypeOptions(['terms', 'date_histogram'])).toEqual([
			{ value: 'terms', label: 'Terms' },
			{ value: 'date_histogram', label: 'Date Histogram' },
		])
		expect(vm.getIntervalOptions(['quarter'])).toEqual([{ value: 'quarter', label: 'Quarter' }])
		expect(vm.getIntervalOptions(undefined).map((option) => option.value)).toEqual(['day', 'week', 'month', 'year'])
	})

	it('selects the option of the active type and interval, or none', () => {
		mockStore.activeFacets = { published: { type: 'date_histogram', config: { interval: 'year' } } }
		const vm = facets()

		expect(vm.getActiveFacetTypeOption('published', ['terms', 'date_histogram'])).toEqual({ value: 'date_histogram', label: 'Date Histogram' })
		expect(vm.getActiveFacetTypeOption('published', undefined)).toBeNull()
		expect(vm.getActiveFacetTypeOption('missing', ['terms'])).toBeNull()
		expect(vm.getActiveFacetIntervalOption('published', undefined)).toEqual({ value: 'year', label: 'Year' })
		expect(vm.getActiveFacetIntervalOption('published', ['day'])).toBeNull()
	})

	it('names a field by its description, else by its formatted name', () => {
		const vm = facets()

		expect(vm.getFieldDisplayName('@self.created', { description: 'Created on' })).toBe('Created on')
		expect(vm.getFieldDisplayName('@self.created', {})).toBe('Self Created')
		expect(vm.getFieldDisplayName('publication_date', {})).toBe('Publication Date')
	})
})
