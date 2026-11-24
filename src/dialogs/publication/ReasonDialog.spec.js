/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reason dialog shows its title, hint and confirm label, asks for a
 * reason and hands it to the caller; it calls nothing itself (REQ-PSG-002,
 * REQ-ATT-104).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
 */

import { mount } from '@vue/test-utils'
import ReasonDialog from './ReasonDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>',
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<textarea />',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<span />' },
}

/**
 * Mount the dialog as the lift embargo dialog.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(ReasonDialog, {
		props: {
			open: false,
			name: 'Lift embargo',
			hint: 'Publish "Besluit parkeren" now, before its publication date?',
			confirmLabel: 'Lift embargo',
			testid: 'lift-embargo',
			...props,
		},
		global: { mocks: { t: T }, stubs },
	})
}

/**
 * The reason text area.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The text area.
 */
function reasonField(wrapper) {
	return wrapper.findComponent({ name: 'NcTextArea' })
}

describe('ReasonDialog', () => {
	it('shows its title and hint and keeps confirm disabled until a reason is given', async () => {
		const wrapper = mountDialog({ open: true })

		expect(wrapper.text()).toContain('Lift embargo')
		expect(wrapper.text()).toContain(
			'Publish "Besluit parkeren" now, before its publication date?',
		)
		expect(
			wrapper
				.find('[data-testid="lift-embargo-confirm"]')
				.attributes('disabled'),
		).toBeDefined()

		reasonField(wrapper).vm.$emit('update:modelValue', '   ')
		await wrapper.vm.$nextTick()
		expect(
			wrapper
				.find('[data-testid="lift-embargo-confirm"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	it('hands the trimmed reason to the caller', async () => {
		const wrapper = mountDialog({ open: true })
		reasonField(wrapper).vm.$emit(
			'update:modelValue',
			'  Raad heeft het besluit vervroegd ',
		)
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="lift-embargo-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([
			[{ reason: 'Raad heeft het besluit vervroegd' }],
		])
	})

	it('starts empty each time it opens, and closes on cancel or when the dialog closes', async () => {
		const wrapper = mountDialog({ open: true })
		wrapper.vm.reason = 'old'
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.reason).toBe('')

		await wrapper.find('[data-testid="lift-embargo-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('allows no confirm while the caller is still working', async () => {
		const wrapper = mountDialog({ open: true, busy: true })
		reasonField(wrapper).vm.$emit('update:modelValue', 'Vervroegd')
		await wrapper.vm.$nextTick()

		expect(
			wrapper
				.find('[data-testid="lift-embargo-confirm"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	it('falls back to the reason test ids', () => {
		const wrapper = mountDialog({ open: true, testid: undefined })

		expect(wrapper.find('[data-testid="reason-confirm"]').exists()).toBe(true)
	})
})
