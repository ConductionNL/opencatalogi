/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The permanent-delete dialog names the publication, says it cannot be undone,
 * and only asks: it calls nothing itself (REQ-PLC-004).
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */

import { mount } from '@vue/test-utils'
import DeleteDraftPermanentlyDialog from './DeleteDraftPermanentlyDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div><slot /><slot name="actions" /></div>',
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
 * Mount the dialog open.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(DeleteDraftPermanentlyDialog, {
		props: { open: true, title: 'Besluit op bezwaar', ...props },
		global: { mocks: { t: T }, stubs },
	})
}

describe('DeleteDraftPermanentlyDialog', () => {
	it('names the publication and says the delete cannot be undone', () => {
		const wrapper = mountDialog()

		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe(
			'Delete permanently',
		)
		expect(wrapper.text()).toContain(
			'Delete "Besluit op bezwaar" permanently? This cannot be undone.',
		)
	})

	it('asks for confirmation and leaves the call to the widget', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="delete-draft-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('closes on Cancel and when the dialog is dismissed', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="delete-draft-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('cannot be confirmed twice while busy', () => {
		const wrapper = mountDialog({ busy: true })

		expect(
			wrapper.find('[data-testid="delete-draft-confirm"]').attributes('disabled'),
		).toBeDefined()
	})
})
