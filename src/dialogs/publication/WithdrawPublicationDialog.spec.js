/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The withdraw dialog asks, for a whole publication, what its public tombstone
 * says: the public reason and whether it shows the title (REQ-PWA-002). For one
 * document it asks only the reason. It calls nothing itself.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.1
 */

import { mount } from '@vue/test-utils'
import WithdrawPublicationDialog from './WithdrawPublicationDialog.vue'

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'disabled', 'helperText'],
		emits: ['update:modelValue'],
		template: '<textarea />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label><slot /></label>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'disabled', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<select />',
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
 * Mount the dialog closed, then open it, so it starts empty.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(props = {}) {
	const wrapper = mount(WithdrawPublicationDialog, {
		props: {
			open: false,
			documents: [{ id: 11, label: 'Besluit.pdf' }],
			...props,
		},
		global: { mocks: { t: T }, stubs },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('WithdrawPublicationDialog', () => {
	it('asks for the public reason and the title choice when the whole publication is withdrawn', async () => {
		const wrapper = await openDialog()

		expect(wrapper.find('[data-testid="withdraw-public-reason"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="withdraw-shows-title"]').text()).toBe(
			'Show the title after withdrawal',
		)

		await wrapper.setData({
			reason: ' AVG-melding ',
			publicReason: ' In strijd met de AVG ',
			tombstoneShowsTitle: true,
		})
		await wrapper.find('[data-testid="withdraw-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')[0][0]).toEqual({
			reason: 'AVG-melding',
			fileId: '',
			publicReason: 'In strijd met de AVG',
			tombstoneShowsTitle: true,
		})
	})

	it('asks only the reason when one document is withdrawn', async () => {
		const wrapper = await openDialog()
		await wrapper.setData({
			reason: 'BSN',
			document: { id: 11, label: 'Besluit.pdf' },
			publicReason: 'left over',
		})

		expect(wrapper.find('[data-testid="withdraw-public-reason"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="withdraw-shows-title"]').exists()).toBe(
			false,
		)

		await wrapper.find('[data-testid="withdraw-confirm"]').trigger('click')
		expect(wrapper.emitted('confirm')[0][0]).toEqual({
			reason: 'BSN',
			fileId: '11',
		})
	})

	it('starts empty, the title not shown, every time it opens', async () => {
		const wrapper = await openDialog()
		await wrapper.setData({ publicReason: 'x', tombstoneShowsTitle: true })

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.publicReason).toBe('')
		expect(wrapper.vm.tombstoneShowsTitle).toBe(false)
	})
})
