/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lift dialog names the publication, asks for a reason and hands it to the
 * widget; it calls nothing itself (REQ-PSG-002).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
 */

import { mount } from '@vue/test-utils'
import LiftEmbargoDialog from './LiftEmbargoDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: { name: 'NcDialog', props: ['open', 'name'], emits: ['update:open'], template: '<div><slot /><slot name="actions" /></div>' },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<textarea />' },
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
}

/**
 * Mount the dialog for one publication.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(LiftEmbargoDialog, {
		props: { open: false, title: 'Besluit parkeren', ...props },
		global: { mocks: { t: T }, stubs },
	})
}

describe('LiftEmbargoDialog', () => {
	it('names the publication and keeps Lift embargo disabled until a reason is given', async () => {
		const wrapper = mountDialog({ open: true })

		expect(wrapper.text()).toContain('Publish "Besluit parkeren" now, before its publication date?')
		expect(wrapper.find('[data-testid="lift-embargo-confirm"]').attributes('disabled')).toBeDefined()

		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '   ')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="lift-embargo-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('hands the trimmed reason to the widget', async () => {
		const wrapper = mountDialog({ open: true })
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '  Raad heeft het besluit vervroegd ')
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="lift-embargo-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Raad heeft het besluit vervroegd' }]])
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

	it('allows no confirm while the widget is still working', async () => {
		const wrapper = mountDialog({ open: true, busy: true })
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Vervroegd')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="lift-embargo-confirm"]').attributes('disabled')).toBeDefined()
	})
})
