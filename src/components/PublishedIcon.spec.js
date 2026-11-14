/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublishedIcon.vue`: which status icon a publication
 * gets, with which tooltip, size and extra class.
 */

import { mount } from '@vue/test-utils'
import PublishedIcon from './PublishedIcon.vue'

const PAST = '2020-01-01T00:00:00Z'
const FUTURE = '2999-01-01T00:00:00Z'

/**
 * @param {object} props The props.
 * @return {object} The rendered icon stub.
 */
function icon(props) {
	return mount(PublishedIcon, { props }).findComponent({ name: 'MaterialDesignIconStub' })
}

describe('PublishedIcon', () => {
	it('shows the published icon for a live publication', () => {
		const rendered = icon({ object: { publicationDate: PAST } })

		expect(rendered.classes()).toContain('published-icon')
		expect(rendered.props('title')).toBe('Published: This publication is live and publicly available')
		expect(rendered.props('size')).toBe(20)
	})

	it('shows the depublished icon once depublished', () => {
		const rendered = icon({ object: { publicationDate: PAST, depublicationDate: PAST } })

		expect(rendered.classes()).toContain('depublished-icon')
		expect(rendered.props('title')).toBe('Depublished: This publication has been withdrawn from public access')
	})

	it('shows the draft icon without a publication date, or a future one', () => {
		expect(icon({ object: {} }).classes()).toContain('unpublished-icon')
		expect(icon({ object: { publicationDate: FUTURE } }).classes()).toContain('unpublished-icon')
		expect(icon({ object: {} }).props('title')).toBe('Draft: This publication is not yet published')
	})

	it('passes its size, extra class and own tooltips through', () => {
		const rendered = icon({ object: {}, size: 32, iconClass: 'extra', draftTooltip: 'Concept' })

		expect(rendered.props('size')).toBe(32)
		expect(rendered.classes()).toEqual(expect.arrayContaining(['unpublished-icon', 'extra']))
		expect(rendered.props('title')).toBe('Concept')
	})
})
