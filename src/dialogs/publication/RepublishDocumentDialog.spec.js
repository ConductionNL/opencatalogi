/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The put-back dialog asks for a reason and hands it to the widget; it calls nothing itself.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
 */

import { mount } from '@vue/test-utils'
import RepublishDocumentDialog from './RepublishDocumentDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: { name: 'NcDialog', props: ['open', 'name'], emits: ['update:open'], template: '<div><slot /><slot name="actions" /></div>' },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<textarea />' },
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
}

/**
 * Mount the dialog for one document.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(RepublishDocumentDialog, {
		props: { open: false, document: { id: 12, label: 'Bijlage.pdf' }, ...props },
		global: { mocks: { t: T }, stubs },
	})
}

describe('RepublishDocumentDialog', () => {
	it('names the document and keeps Republish disabled until a reason is given', async () => {
		const wrapper = mountDialog({ open: true })

		expect(wrapper.text()).toContain('Bijlage.pdf becomes public again on the public site and in the sitemap.')
		expect(wrapper.find('[data-testid="republish-confirm"]').attributes('disabled')).toBeDefined()

		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '   ')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="republish-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('hands the trimmed reason to the widget', async () => {
		const wrapper = mountDialog({ open: true })
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '  Woonadres is weggelakt ')
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="republish-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Woonadres is weggelakt' }]])
	})

	it('starts empty each time it opens, and closes on cancel or when the dialog closes', async () => {
		const wrapper = mountDialog({ open: true })
		wrapper.vm.reason = 'old'
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.reason).toBe('')

		await wrapper.findAll('button')[0].trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('stays busy: no confirm while the widget is still working', () => {
		const wrapper = mountDialog({ open: true, busy: true })
		wrapper.vm.reason = 'x'

		expect(wrapper.find('[data-testid="republish-confirm"]').attributes('disabled')).toBeDefined()
	})
})
