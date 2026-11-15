/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `AuditTrailWidget.vue`: the audit trail card renders only
 * once register, schema and object id are all known, and receives them (and
 * the title) as the card expects them.
 */

import { mount } from '@vue/test-utils'
import AuditTrailWidget from './AuditTrailWidget.vue'

const CardStub = {
	name: 'CnAuditTrailCard',
	props: ['register', 'schema', 'objectId', 'title'],
	template: '<div class="audit-card-stub" />',
}

/**
 * Mount the widget with props and an injected context.
 *
 * @param {object} props The widget props.
 * @param {object} [provide] The injected context.
 * @return {object} The wrapper.
 */
function mountWidget(props, provide = {}) {
	return mount(AuditTrailWidget, {
		props,
		global: { provide, stubs: { CnAuditTrailCard: CardStub } },
	})
}

describe('AuditTrailWidget', () => {
	it('renders no card while any of register, schema or object id is missing', () => {
		expect(mountWidget({}).findComponent(CardStub).exists()).toBe(false)
		expect(mountWidget({ register: 'r', schema: 's' }).findComponent(CardStub).exists()).toBe(false)
		expect(mountWidget({ register: 'r', objectId: 'o' }).findComponent(CardStub).exists()).toBe(false)
		expect(mountWidget({ schema: 's', objectId: 'o' }).findComponent(CardStub).exists()).toBe(false)
	})

	it('passes the props through, with a schema object reduced to its slug', () => {
		const card = mountWidget({ register: 'r', schema: { slug: 'pub', id: 9 }, objectId: 'o', title: 'History' }).findComponent(CardStub)

		expect(card.exists()).toBe(true)
		expect(card.props()).toEqual({ register: 'r', schema: 'pub', objectId: 'o', title: 'History' })
	})

	it('takes the object from the injected context when no props are given', () => {
		const card = mountWidget({}, { cnObjectContext: { value: { register: 'ir', schema: { name: 'iname' }, objectId: 'io' } } }).findComponent(CardStub)

		expect(card.props()).toEqual({ register: 'ir', schema: 'iname', objectId: 'io', title: '' })
	})

	it('falls back to the content blob, title included', () => {
		const card = mountWidget({ content: { register: 'cr', schema: 'cs', objectId: 'co', title: 'Content title' } }).findComponent(CardStub)

		expect(card.props()).toEqual({ register: 'cr', schema: 'cs', objectId: 'co', title: 'Content title' })
	})

	it('prefers its own title prop over the content title', () => {
		const card = mountWidget({ title: 'Own', content: { register: 'cr', schema: 'cs', objectId: 'co', title: 'Content title' } }).findComponent(CardStub)

		expect(card.props('title')).toBe('Own')
	})
})
