/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Council documents section as rendered: it shows the sources integriq
 * holds, warns when decidiq publishes too, posts set-up with the chosen
 * source, the switches and Run now, and shows the server's refusal. Only HTTP
 * and the Nextcloud globals are mocked; the @nextcloud/vue components are
 * stubbed to render their slots.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import CouncilHarvest from './CouncilHarvest.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (m, key) => vars[key]),
}))
jest.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	const slotted = (tag, cls) => ({
		inheritAttrs: false,
		props: ['type', 'variant', 'disabled'],
		emits: ['click'],
		render() {
			return render(tag, { class: cls, 'data-type': this.type, disabled: this.disabled, 'data-testid': this.$attrs['data-testid'], onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	})
	return {
		NcButton: slotted('button', 'nc-button'),
		NcNoteCard: slotted('div', 'nc-note-card'),
		NcLoadingIcon: slotted('span', 'nc-loading'),
		NcSelect: {
			inheritAttrs: false,
			props: ['modelValue', 'options', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return render('select', {
					'data-testid': this.$attrs['data-testid'],
					'aria-label': this.inputLabel,
					onChange: (e) => this.$emit('update:modelValue', this.options[Number(e.target.value)]),
				}, (this.options ?? []).map((option, index) => render('option', { value: index }, option.label)))
			},
		},
		NcCheckboxRadioSwitch: {
			inheritAttrs: false,
			props: ['modelValue', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return render('input', {
					type: 'checkbox',
					checked: this.modelValue,
					disabled: this.disabled,
					'data-testid': this.$attrs['data-testid'],
					onChange: (e) => this.$emit('update:modelValue', e.target.checked),
				})
			},
		},
	}
})

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))
const options = { global: { mocks: { t }, stubs: { InformationOutline: true } } }
const notSetUp = {
	integriq: true,
	decidiq: false,
	sources: [{ slug: 'notubiz-ris-v1', label: 'Notubiz', uuid: 'src-n' }, { slug: 'ibabs-ris-v1', label: 'iBabs', uuid: 'src-i' }],
	source: null,
	publishOnArrival: false,
	flow: { uuid: null, imported: false, enabled: false, cron: null },
	lastRun: null,
}
const on = { ...notSetUp, source: 'ibabs-ris-v1', flow: { uuid: 'f', imported: true, enabled: true, cron: '30 2 * * *' } }

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('CouncilHarvest rendered', () => {
	it('lists the council sources under a labelled select and says it is not set up', async () => {
		axios.get.mockResolvedValue({ data: notSetUp })
		const wrapper = mount(CouncilHarvest, options)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/council-harvest')
		const select = wrapper.find('[data-testid="council-harvest-source"]')
		expect(select.attributes('aria-label')).toBe('Source')
		expect(select.findAll('option').map((o) => o.text())).toEqual(['Notubiz', 'iBabs'])
		expect(wrapper.find('[data-testid="council-harvest-flow"]').text()).toBe('Not set up yet. Choose a source and press Set up.')
		expect(wrapper.find('[data-testid="council-harvest-setup"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="council-harvest-decidiq"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="council-harvest-source-link"]').exists()).toBe(false)
	})

	it('posts set-up with the chosen source and reads the status again', async () => {
		axios.get.mockResolvedValueOnce({ data: notSetUp }).mockResolvedValue({ data: on })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mount(CouncilHarvest, options)
		await flushPromises()

		await wrapper.find('[data-testid="council-harvest-source"]').setValue('1')
		await wrapper.find('[data-testid="council-harvest-setup"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/council-harvest/setup', { source: 'ibabs-ris-v1' })
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="council-harvest-flow"]').text()).toBe('The harvest is on. It runs on schedule 30 2 * * *.')
		expect(wrapper.find('[data-testid="council-harvest-source-link"]').attributes('href')).toBe('/apps/integriq/sources/src-i')
		expect(wrapper.find('[data-testid="council-harvest-enable"]').exists()).toBe(false)
	})

	it('posts Switch off, Run now and Publish on arrival', async () => {
		axios.get.mockResolvedValue({ data: on })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mount(CouncilHarvest, options)
		await flushPromises()

		await wrapper.find('[data-testid="council-harvest-run"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/settings/council-harvest/run', {})

		await wrapper.find('[data-testid="council-harvest-disable"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/settings/council-harvest/disable', {})

		await wrapper.find('[data-testid="council-harvest-publish"]').setValue(true)
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/settings/council-harvest/publish-on-arrival', { publish: true })
	})

	it('shows the refusal the server names, and warns when decidiq publishes too', async () => {
		axios.get.mockResolvedValue({ data: { ...on, decidiq: true } })
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Fill in the iBabs key in integriq first.' } } })
		const wrapper = mount(CouncilHarvest, options)
		await flushPromises()

		expect(wrapper.find('[data-testid="council-harvest-decidiq"]').text()).toBe('decidiq already publishes council papers here. Running both creates duplicates.')
		await wrapper.find('[data-testid="council-harvest-setup"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.nc-note-card[data-type="error"]').text()).toBe('Fill in the iBabs key in integriq first.')
	})

	it('says when integriq is missing, and when the status could not be loaded', async () => {
		axios.get.mockResolvedValueOnce({ data: { ...notSetUp, integriq: false } })
		const wrapper = mount(CouncilHarvest, options)
		await flushPromises()
		expect(wrapper.find('[data-testid="council-harvest-needs-integriq"]').exists()).toBe(true)

		axios.get.mockRejectedValueOnce(new Error('500'))
		const failed = mount(CouncilHarvest, options)
		await flushPromises()
		expect(failed.find('.nc-note-card[data-type="error"]').text()).toBe('The council documents harvest status could not be loaded.')
	})
})
