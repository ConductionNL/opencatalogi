/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publishing a publication with no documents asks twice: a warning naming the
 * publication, and Publish disabled until the editor ticks the confirmation
 * (REQ-PSG-004). The dialog only asks; it calls nothing itself.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */

import { mount } from '@vue/test-utils'
import PublishWithoutDocumentsDialog from './PublishWithoutDocumentsDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<span />' },
}

/**
 * Mount the dialog.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(PublishWithoutDocumentsDialog, {
		props: { open: true, title: 'Besluit parkeren', ...props },
		global: { mocks: { t: T }, stubs },
	})
}

const CONFIRM = '[data-testid="publish-without-documents-confirm"]'

describe('PublishWithoutDocumentsDialog', () => {
	it('warns that the named publication has no documents', () => {
		const text = mountDialog().find('[data-testid="publish-without-documents-warning"]').text()
		expect(text).toContain('"Besluit parkeren" has no documents')
		expect(text).toContain('no files and no document references')
	})

	it('keeps Publish disabled until the confirmation is ticked', async () => {
		const wrapper = mountDialog()
		expect(wrapper.find(CONFIRM).attributes('disabled')).toBeDefined()

		await wrapper.find('input[type="checkbox"]').setValue(true)

		expect(wrapper.find(CONFIRM).attributes('disabled')).toBeUndefined()
		await wrapper.find(CONFIRM).trigger('click')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('keeps Publish disabled while busy, even when ticked', async () => {
		const wrapper = mountDialog({ busy: true })
		wrapper.vm.confirmed = true
		await wrapper.vm.$nextTick()
		expect(wrapper.find(CONFIRM).attributes('disabled')).toBeDefined()
	})

	it('clears the tick each time it opens', async () => {
		const wrapper = mountDialog({ open: false })
		wrapper.vm.confirmed = true
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.confirmed).toBe(false)
	})

	it('closes on Cancel and when the dialog is dismissed', async () => {
		const wrapper = mountDialog()
		await wrapper.find('[data-testid="publish-without-documents-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})
