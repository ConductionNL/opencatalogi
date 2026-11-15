/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationCard.vue`: the title, summary, status line
 * and file count a card shows, and the click and select it emits.
 */

import { mount } from '@vue/test-utils'
import PublicationCard from './PublicationCard.vue'

const PAST = '2020-01-01T00:00:00.000Z'
const FUTURE = '2999-01-01T00:00:00.000Z'

/**
 * @param {object} object The publication.
 * @param {object} options Extra props and slots.
 * @return {object} The wrapper.
 */
function card(object, options = {}) {
	return mount(PublicationCard, { props: { object, ...(options.props || {}) }, slots: options.slots })
}

describe('PublicationCard', () => {
	it('titles the card by @self.name, title, name, id or a dash', () => {
		expect(card({ '@self': { name: 'A' }, title: 'B' }).find('h3').text()).toBe('A')
		expect(card({ title: 'B', name: 'C' }).find('h3').text()).toBe('B')
		expect(card({ name: 'C' }).find('h3').text()).toBe('C')
		expect(card({ id: 'x1' }).find('h3').text()).toBe('x1')
		expect(card({}).find('h3').text()).toBe('—')
	})

	it('shows a short summary as is and cuts a long one at 120 characters', () => {
		expect(card({ summary: 'Kort' }).find('.publication-card__summary').text()).toBe('Kort')
		expect(card({ summary: 'x'.repeat(130) }).find('.publication-card__summary').text()).toBe('x'.repeat(120) + '...')
		expect(card({}).find('.publication-card__summary').exists()).toBe(false)
	})

	it('says concept, scheduled, published or depublished', () => {
		expect(card({}).find('.publication-card__status').text()).toBe('Concept')
		expect(card({ publicationDate: FUTURE }).find('.publication-card__status').text()).toBe(`Scheduled for ${new Date(FUTURE).toLocaleString()}`)
		expect(card({ publicationDate: PAST }).find('.publication-card__status').text()).toBe(`Published on ${new Date(PAST).toLocaleString()}`)
		expect(card({ publicationDate: PAST, depublicationDate: PAST }).find('.publication-card__status').text()).toBe(`Depublished on ${new Date(PAST).toLocaleString()}`)
		expect(card({}).classes()).toContain('publication-card--concept')
	})

	it('shows a date it cannot read as it is', () => {
		expect(card({ publicationDate: PAST, depublicationDate: PAST }).vm.formatDate('soon')).toBe('soon')
		expect(card({}).vm.formatDate(null)).toBe('N/A')
	})

	it('counts the files from the counters, the file list or a single file', () => {
		const count = (self) => card({ '@self': self }).findComponent({ name: 'NcCounterBubble' }).vm.$attrs.count

		expect(count({ filesCount: 4 })).toBe(4)
		expect(count({ attachmentsCount: 2 })).toBe(2)
		expect(count({ attachmentCount: 6 })).toBe(6)
		expect(count({ files: [{}, {}, {}] })).toBe(3)
		expect(count({ files: { id: 1 } })).toBe(1)
		expect(count({})).toBe(0)
	})

	it('emits click with the publication on click, Enter and Space', async () => {
		const object = { id: 1 }
		const wrapper = card(object)

		await wrapper.trigger('click')
		await wrapper.trigger('keydown.enter')
		await wrapper.trigger('keydown.space')

		expect(wrapper.emitted('click')).toEqual([[object], [object], [object]])
	})

	it('offers a selection switch only when selectable, and emits select from it', () => {
		const object = { id: 1, title: 'Besluit' }
		expect(card(object).findComponent({ name: 'NcCheckboxRadioSwitch' }).exists()).toBe(false)

		const wrapper = card(object, { props: { selectable: true, selected: true } })
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		toggle.vm.$emit('update:modelValue', false)

		expect(toggle.vm.$attrs['aria-label']).toBe('Select Besluit')
		expect(toggle.vm.$attrs.modelValue).toBe(true)
		expect(wrapper.classes()).toContain('publication-card--selected')
		expect(wrapper.emitted('select')).toEqual([[object]])
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('renders the actions slot with the publication', () => {
		const wrapper = card({ id: 7 }, { slots: { actions: '<template #actions="{ object }"><i class="act">{{ object.id }}</i></template>' } })

		expect(wrapper.find('.publication-card__actions .act').text()).toBe('7')
		expect(card({ id: 7 }).find('.publication-card__actions').exists()).toBe(false)
	})
})
